<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncAccountJob;
use App\Models\Account;
use App\Models\Message;
use App\Services\Google\GoogleClientFactory;
use Google\Client;
use Google\Service\Oauth2\Userinfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * "Remove account" keeps the user in charge of the local copy: disconnect
 * (tokens gone, mail stays, reconnect continues) or delete everything.
 */
class DisconnectAccountTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Account
    {
        return $this->makeAccount(['display_name' => 'Me']);
    }

    private function message(Account $account, string $id): Message
    {
        return Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => $id, 'subject' => 'S '.$id, 'from_email' => 'a@b.c',
            'to_recipients' => [], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [], 'headers' => [],
            'label_ids' => ['INBOX'], 'received_at' => now(), 'sent_at' => now(),
        ]);
    }

    public function test_disconnect_forgets_tokens_but_keeps_the_mailbox(): void
    {
        Queue::fake();
        $account = $this->account();
        $message = $this->message($account, 'm1');
        $this->putJson("/api/messages/{$message->id}/local-draft", ['kind' => 'reply', 'body' => 'unsent'])->assertOk();

        $this->deleteJson("/api/accounts/{$account->id}", ['keep_mail' => true])
            ->assertOk()
            ->assertJsonPath('disconnected', true)
            ->assertJsonPath('account.sync_status', 'disconnected')
            ->assertJsonPath('account.can_modify', false);

        $fresh = $account->fresh();
        $this->assertNull($fresh->access_token);
        $this->assertNull($fresh->refresh_token);
        $this->assertSame([], $fresh->scopes);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('local_drafts', 1);

        // Still readable offline ...
        $this->getJson("/api/accounts/{$account->id}/messages")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/messages/{$message->id}")->assertOk();

        // ... but nothing can reach Google any more: no sync, no send, no mark-as-read mirroring.
        $this->noGmail();
        $this->postJson("/api/accounts/{$account->id}/sync")->assertStatus(409);
        Queue::assertNothingPushed();
        $this->postJson("/api/accounts/{$account->id}/messages/send", [
            'to' => ['x@y.z'], 'subject' => 's', 'body' => 'b', 'confirm_send' => true, 'idempotency_key' => '9c7d2b1e-1111-4222-8333-444455556666',
        ])->assertStatus(409)->assertJsonPath('needs_reauth', true);
        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => true])->assertOk()->assertJsonPath('remote', 'skipped');

        // The scheduler skips it too.
        $this->artisan('sovera:sync-accounts')->expectsOutput('No accounts to sync.');
    }

    public function test_reconnecting_a_disconnected_account_continues_from_the_local_copy(): void
    {
        Queue::fake();
        $account = $this->account();
        $this->message($account, 'm1');
        $account->disconnect();

        config(['sovera.google.client_id' => 'id', 'sovera.google.client_secret' => 'secret']);
        Cache::put('oauth:google:state:s1', true, 60);

        $userinfo = new Userinfo(['email' => 'me@example.com', 'name' => 'Me']);
        $this->mock(GoogleClientFactory::class, function (MockInterface $m) use ($userinfo) {
            $client = Mockery::mock(Client::class);
            $client->shouldReceive('fetchAccessTokenWithAuthCode')->once()->andReturn([
                'access_token' => 'new-at', 'refresh_token' => 'new-rt', 'expires_in' => 3600,
                'scope' => 'openid '.Account::SCOPE_GMAIL_MODIFY,
            ]);
            $client->shouldReceive('setAccessToken')->once();
            $m->shouldReceive('make')->andReturn($client);
            $m->shouldReceive('userinfo')->once()->andReturn($userinfo);
        });

        $response = $this->get('/api/auth/google/callback?code=abc&state=s1');
        $response->assertOk();

        $fresh = $account->fresh();
        $this->assertSame('new-at', $fresh->access_token);
        $this->assertSame(Account::STATUS_QUEUED, $fresh->sync_status);
        $this->assertTrue($fresh->canModify());
        $this->assertDatabaseCount('accounts', 1);   // same row, no duplicate account
        $this->assertDatabaseCount('messages', 1);   // nothing re-downloaded, nothing lost
        Queue::assertPushed(SyncAccountJob::class, fn (SyncAccountJob $j) => $j->accountId === $account->id);
    }

    public function test_delete_removes_the_local_copy_and_cached_attachments(): void
    {
        Storage::fake('local');
        $account = $this->account();
        $message = $this->message($account, 'm1');
        Storage::disk('local')->put("attachments/{$account->id}/{$message->id}/1", 'bytes');

        $this->deleteJson("/api/accounts/{$account->id}", ['keep_mail' => false])->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('messages', 0);
        Storage::disk('local')->assertMissing("attachments/{$account->id}/{$message->id}/1");
    }

    public function test_cli_offers_the_same_two_modes(): void
    {
        $account = $this->account();
        $this->message($account, 'm1');

        $this->artisan('sovera:remove-account', ['email' => 'me@example.com'])->assertSuccessful();   // dry run
        $this->assertDatabaseCount('accounts', 1);

        $this->artisan('sovera:remove-account', ['email' => 'me@example.com', '--keep-mail' => true, '--force' => true])->assertSuccessful();
        $this->assertTrue($account->fresh()->isDisconnected());
        $this->assertDatabaseCount('messages', 1);

        $this->artisan('sovera:remove-account', ['email' => 'me@example.com', '--force' => true])
            ->expectsQuestion('Type the address me@example.com to confirm deleting the local copy', 'wrong')
            ->assertFailed();
        $this->assertDatabaseCount('accounts', 1);

        $this->artisan('sovera:remove-account', ['email' => 'me@example.com', '--force' => true])
            ->expectsQuestion('Type the address me@example.com to confirm deleting the local copy', 'me@example.com')
            ->assertSuccessful();
        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('messages', 0);
    }
}
