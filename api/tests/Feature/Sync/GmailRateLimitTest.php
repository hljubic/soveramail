<?php

namespace Tests\Feature\Sync;

use App\Exceptions\ReauthRequiredException;
use App\Services\Gmail\GmailClient;
use App\Services\Google\GoogleClientFactory;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\Resource\UsersMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

/**
 * Gmail's per-user quota ("Units per minute per user") must pause the sync,
 * never fail it. Auth problems still surface immediately.
 */
class GmailRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function client(UsersMessages $messages): GmailClient
    {
        config(['sovera.google.client_id' => 'id', 'sovera.google.client_secret' => 'secret']);
        $factory = app(GoogleClientFactory::class);
        $gmail = new Gmail($factory->make());
        $gmail->users_messages = $messages;

        return new GmailClient($this->makeAccount(), $factory, $gmail);
    }

    private function quotaError(): GoogleServiceException
    {
        return new GoogleServiceException(
            "Quota exceeded for quota metric 'Total Query Cost' and limit 'Units per minute per user' of service 'gmail.googleapis.com'",
            403,
            null,
            [['reason' => 'rateLimitExceeded', 'message' => 'Quota exceeded']],
        );
    }

    public function test_rate_limited_call_is_retried_after_a_pause(): void
    {
        Sleep::fake();
        $script = [$this->quotaError(), $this->quotaError(), new GmailMessage(['id' => 'm1'])];
        $messages = Mockery::mock(UsersMessages::class);
        $messages->shouldReceive('get')->times(3)->andReturnUsing(function () use (&$script) {
            $next = array_shift($script);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        $this->assertSame('m1', $this->client($messages)->getMessage('m1')?->getId());

        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(15)->seconds()]);
    }

    public function test_gives_up_after_the_last_retry(): void
    {
        Sleep::fake();
        $messages = Mockery::mock(UsersMessages::class);
        $messages->shouldReceive('get')->times(6)->andThrow($this->quotaError());

        $this->expectException(GoogleServiceException::class);
        $this->client($messages)->getMessage('m1');
    }

    /** The factory with refresh() faked: no real call to Google's token endpoint. */
    private function factoryWithRefresh(int $times, ?\Throwable $fails = null): GoogleClientFactory
    {
        $factory = Mockery::mock(GoogleClientFactory::class)->makePartial();
        $expectation = $factory->shouldReceive('refresh')->times($times);
        $fails
            ? $expectation->andThrow($fails)
            : $expectation->andReturnUsing(function ($account, $client) {
                $account->forceFill(['access_token' => 'fresh', 'token_expires_at' => now()->addHour()])->save();

                return $client;
            });
        $this->app->instance(GoogleClientFactory::class, $factory);

        return $factory;
    }

    public function test_a_401_refreshes_the_token_once_and_retries(): void
    {
        Sleep::fake();
        $this->factoryWithRefresh(1);
        $script = [new GoogleServiceException('Invalid Credentials', 401), new GmailMessage(['id' => 'm1'])];
        $messages = Mockery::mock(UsersMessages::class);
        $messages->shouldReceive('get')->twice()->andReturnUsing(function () use (&$script) {
            $next = array_shift($script);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        $this->assertSame('m1', $this->client($messages)->getMessage('m1')?->getId());
        Sleep::assertNeverSlept();
    }

    public function test_a_second_401_after_refreshing_asks_to_reconnect(): void
    {
        Sleep::fake();
        $this->factoryWithRefresh(1);
        $messages = Mockery::mock(UsersMessages::class);
        $messages->shouldReceive('get')->twice()->andThrow(new GoogleServiceException('Invalid Credentials', 401));

        $this->expectException(ReauthRequiredException::class);
        try {
            $this->client($messages)->getMessage('m1');
        } finally {
            Sleep::assertNeverSlept();
        }
    }

    public function test_missing_permission_is_never_retried(): void
    {
        Sleep::fake();
        $this->factoryWithRefresh(0);
        $messages = Mockery::mock(UsersMessages::class);
        $messages->shouldReceive('get')->once()->andThrow(new GoogleServiceException('forbidden', 403, null, [['reason' => 'insufficientPermissions']]));

        $this->expectException(ReauthRequiredException::class);
        $this->client($messages)->getMessage('m1');
    }

    public function test_a_token_about_to_expire_is_renewed_before_the_call(): void
    {
        $this->factoryWithRefresh(1);
        $messages = Mockery::mock(UsersMessages::class);
        $messages->shouldReceive('get')->once()->andReturn(new GmailMessage(['id' => 'm1']));

        config(['sovera.google.client_id' => 'id', 'sovera.google.client_secret' => 'secret']);
        $gmail = new Gmail(app(GoogleClientFactory::class)->make());
        $gmail->users_messages = $messages;
        // Still valid when the client is built (forAccount leaves it), but inside the 2-minute renewal window.
        $account = $this->makeAccount(['token_expires_at' => now()->addSeconds(90)]);

        $this->assertSame('m1', (new GmailClient($account, app(GoogleClientFactory::class), $gmail))->getMessage('m1')?->getId());
        $this->assertSame('fresh', $account->fresh()->access_token);
    }

    public function test_is_rate_limited_recognises_gmail_quota_replies(): void
    {
        $this->assertTrue(GmailClient::isRateLimited($this->quotaError()));
        $this->assertTrue(GmailClient::isRateLimited(new GoogleServiceException('slow down', 429)));
        $this->assertTrue(GmailClient::isRateLimited(new GoogleServiceException("Quota exceeded for quota metric 'Total Query Cost'", 403)));
        $this->assertFalse(GmailClient::isRateLimited(new GoogleServiceException('forbidden', 403, null, [['reason' => 'insufficientPermissions']])));
        $this->assertFalse(GmailClient::isRateLimited(new GoogleServiceException('not found', 404)));
    }
}
