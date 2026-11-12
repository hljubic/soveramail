<?php

namespace Tests;

use App\Models\Account;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailClientResolver;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    protected $defaultHeaders = ['X-Sovera-Client' => 'tests'];

    /** A connected Gmail account with full (modify) access; override any column. */
    protected function makeAccount(array $attrs = []): Account
    {
        return Account::query()->create(array_merge([
            'provider' => 'gmail', 'email' => 'me@example.com', 'access_token' => 'at', 'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(), 'scopes' => [Account::SCOPE_GMAIL_MODIFY], 'initial_sync_done' => true, 'history_id' => '1',
        ], $attrs));
    }

    /** A minimal text/plain message as the Gmail API returns it (format=full). */
    protected function gmailMessage(string $id, array $labels, string $subject, string $body, string $from = 'me@example.com', ?string $threadId = null): GmailMessage
    {
        return new GmailMessage([
            'id' => $id, 'threadId' => $threadId ?? 't-'.$id, 'labelIds' => $labels, 'internalDate' => (string) now()->getTimestampMs(), 'snippet' => $body,
            'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'From', 'value' => $from], ['name' => 'Subject', 'value' => $subject]],
                'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]],
        ]);
    }

    /** Every account resolves to this Gmail client mock. */
    protected function fakeGmail(): MockInterface
    {
        $client = Mockery::mock(GmailClient::class);
        $client->shouldReceive('rateLimitHits')->andReturn(0)->byDefault();
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')->andReturn($client));

        return $client;
    }

    /** Google must not be contacted at all. */
    protected function noGmail(): void
    {
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldNotReceive('for'));
    }
}
