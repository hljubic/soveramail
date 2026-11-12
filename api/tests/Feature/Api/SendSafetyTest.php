<?php

namespace Tests\Feature\Api;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Models\Message;
use App\Models\OutgoingMailAttempt;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailClientResolver;
use App\Services\Mail\OutgoingMailService;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail\Draft;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Mail leaves this app only through an explicit, confirmed Send, and never twice.
 */
class SendSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '3d3a1f0e-2b1c-4c5d-9e8f-0a1b2c3d4e5f';

    private function account(): Account
    {
        return $this->makeAccount(['display_name' => 'Me']);
    }

    private function message(Account $account): Message
    {
        return Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'gm-1', 'thread_id' => 'thr-1', 'subject' => 'Ponuda',
            'from_name' => 'Ana', 'from_email' => 'ana@example.com', 'to_recipients' => [], 'cc_recipients' => [], 'bcc_recipients' => [],
            'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'body_text' => 'evo ponude', 'received_at' => now(), 'sent_at' => now(),
        ]);
    }

    private function sentCopy(string $id): GmailMessage
    {
        return $this->gmailMessage($id, ['SENT'], 'x', 'x');
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok', 'confirm_send' => true, 'idempotency_key' => self::KEY];
    }

    public function test_every_send_route_needs_confirmation_and_a_key(): void
    {
        $account = $this->account();
        $message = $this->message($account);
        $this->noGmail();

        $bare = ['to' => ['ana@example.com'], 'subject' => 'x', 'body' => 'y'];
        foreach (["/api/accounts/{$account->id}/messages/send", "/api/messages/{$message->id}/reply", "/api/messages/{$message->id}/forward"] as $url) {
            $this->postJson($url, $bare)->assertStatus(422)->assertJsonValidationErrors(['confirm_send', 'idempotency_key']);
            $this->postJson($url, $bare + ['confirm_send' => false, 'idempotency_key' => self::KEY])->assertStatus(422)->assertJsonValidationErrors(['confirm_send']);
            $this->postJson($url, $bare + ['confirm_send' => true, 'idempotency_key' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
        }
        $this->assertDatabaseCount('outgoing_mail_attempts', 0);
    }

    public function test_invalid_recipients_are_rejected_before_anything_happens(): void
    {
        $account = $this->account();
        $this->noGmail();

        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload(['to' => ['ana']]))
            ->assertStatus(422)->assertJsonValidationErrors(['to.0']);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload(['cc' => ['Ana <not an address>']]))
            ->assertStatus(422)->assertJsonValidationErrors(['cc.0']);
        $this->postJson("/api/accounts/{$account->id}/messages/draft", ['to' => ['nope'], 'body' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['to.0']);

        $this->assertDatabaseCount('outgoing_mail_attempts', 0);
    }

    public function test_same_key_with_different_content_never_sends(): void
    {
        $account = $this->account();
        $client = $this->fakeGmail();
        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-1', 'threadId' => 't']));
        $client->shouldReceive('getMessage')->once()->andReturn($this->sentCopy('gm-1'));

        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload())->assertCreated();

        // Same key, edited text: refused, and the first message is not re-sent either.
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload(['body' => 'Bok (edited)']))
            ->assertStatus(409)->assertJsonPath('duplicate_prevented', true);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload(['to' => ['pero@example.com']]))
            ->assertStatus(409)->assertJsonPath('duplicate_prevented', true);

        $this->assertDatabaseCount('outgoing_mail_attempts', 1);
    }

    public function test_a_send_still_in_flight_blocks_a_second_request_with_the_same_key(): void
    {
        $account = $this->account();
        $this->noGmail();

        // Request A claimed the key and is still talking to Gmail.
        $input = ['to' => ['ana@example.com'], 'cc' => [], 'bcc' => [], 'subject' => 'Pozdrav', 'body' => 'Bok', 'include_attachments' => true];
        OutgoingMailAttempt::query()->create([
            'account_id' => $account->id, 'idempotency_key' => self::KEY, 'status' => OutgoingMailAttempt::STATUS_PROCESSING,
            'payload_hash' => OutgoingMailService::payloadHash('new', $account->id, null, $input),
        ]);

        // Request B (double click, retry): refused without contacting Gmail.
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload())
            ->assertStatus(409)->assertJsonPath('duplicate_prevented', true);
        $this->assertDatabaseCount('outgoing_mail_attempts', 1);
    }

    public function test_failure_before_gmail_is_contacted_releases_the_key(): void
    {
        $account = $this->account();
        $client = Mockery::mock(GmailClient::class);
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')
            ->once()->andThrow(new ReauthRequiredException('Token refresh failed'))->ordered()
            ->shouldReceive('for')->once()->andReturn($client)->ordered());
        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-1', 'threadId' => 't']));
        $client->shouldReceive('getMessage')->once()->andReturn($this->sentCopy('gm-1'));

        // Expired grant: nothing was sent, the account is flagged, the key is free again.
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload())
            ->assertStatus(409)->assertJsonPath('needs_reauth', true);
        $this->assertSame(Account::STATUS_NEEDS_REAUTH, $account->fresh()->sync_status);
        $this->assertDatabaseCount('outgoing_mail_attempts', 0);

        // After reconnecting the same compose window can send, exactly once.
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload())->assertCreated();
        $this->assertDatabaseCount('outgoing_mail_attempts', 1);
    }

    public function test_gmail_rejection_releases_the_key_but_a_timeout_keeps_it(): void
    {
        $account = $this->account();
        $client = $this->fakeGmail();

        // 1. Gmail answers 400: definitely not sent -> the user may fix and retry.
        $client->shouldReceive('sendRaw')->once()->andThrow(new GoogleServiceException('{"error":{"message":"Recipient address required"}}', 400))->ordered();
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload())->assertStatus(502);
        $this->assertDatabaseCount('outgoing_mail_attempts', 0);

        // 2. Retry with the same key works (one real send).
        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-1', 'threadId' => 't']))->ordered();
        $client->shouldReceive('getMessage')->once()->andReturn($this->sentCopy('gm-1'));
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload())->assertCreated();

        // 3. A different message whose send times out: outcome unknown -> key stays burned.
        $key2 = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $client->shouldReceive('sendRaw')->once()->andThrow(new \RuntimeException('cURL error 28: Operation timed out'))->ordered();
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload(['idempotency_key' => $key2, 'body' => 'Drugi']))->assertStatus(500);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->payload(['idempotency_key' => $key2, 'body' => 'Drugi']))
            ->assertStatus(409)->assertJsonPath('duplicate_prevented', true);

        $this->assertSame(OutgoingMailAttempt::STATUS_UNCERTAIN, OutgoingMailAttempt::query()->where('idempotency_key', $key2)->value('status'));
        $this->assertDatabaseCount('outgoing_mail_attempts', 2);
    }

    public function test_reply_to_an_expired_grant_flags_the_account_instead_of_crashing(): void
    {
        $account = $this->account();
        $message = $this->message($account);
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')->once()->andThrow(new ReauthRequiredException('revoked')));

        $this->postJson("/api/messages/{$message->id}/reply", $this->payload(['body' => 'Hvala']))
            ->assertStatus(409)->assertJsonPath('needs_reauth', true);
        $this->assertSame(Account::STATUS_NEEDS_REAUTH, $account->fresh()->sync_status);
        $this->assertDatabaseCount('outgoing_mail_attempts', 0);
    }

    public function test_drafts_never_send(): void
    {
        $account = $this->account();
        $message = $this->message($account);
        $client = $this->fakeGmail();
        $client->shouldNotReceive('sendRaw');
        $client->shouldReceive('createDraft')->twice()->andReturn(new Draft(['id' => 'd', 'message' => ['id' => 'gm-d', 'threadId' => 't']]));
        $client->shouldReceive('getMessage')->twice()->andReturn($this->sentCopy('gm-d'));

        // Even a "confirmed" payload on the draft routes only creates a Gmail draft.
        $this->postJson("/api/accounts/{$account->id}/messages/draft", $this->payload())->assertCreated();
        $this->postJson("/api/messages/{$message->id}/draft", $this->payload(['kind' => 'reply']))->assertCreated();
        $this->assertDatabaseCount('outgoing_mail_attempts', 0);
    }

    public function test_only_the_outgoing_mail_service_can_reach_gmail_send(): void
    {
        $callers = [];
        foreach (File::allFiles(app_path()) as $file) {
            $src = $file->getContents();
            $rel = $file->getRelativePathname();
            if ($rel === 'Services/Gmail/GmailClient.php') {
                $this->assertSame(1, substr_count($src, 'users_messages->send('), 'GmailClient must have exactly one send call');

                continue;
            }
            $this->assertStringNotContainsString('users_messages->send(', $src, "{$rel} bypasses GmailClient::sendRaw");
            $this->assertStringNotContainsString('users_drafts->send(', $src, "{$rel} sends a draft");
            if (str_contains($src, 'sendRaw(') || str_contains($src, '->send($account')) {
                $callers[] = $rel;
            }
        }
        sort($callers);
        // The Gmail adapter wraps the API call; the outgoing service is the only caller of a provider's send().
        $this->assertSame(['Services/Mail/OutgoingMailService.php', 'Services/Mail/Providers/GmailProvider.php', 'Services/Mail/Providers/ImapProvider.php'], $callers);

        // Background work (sync, scheduler, AI) never even references the send path.
        foreach (['Jobs', 'Console', 'Services/Ai', 'Services/Sync'] as $dir) {
            foreach (File::allFiles(app_path($dir)) as $file) {
                $this->assertStringNotContainsString('OutgoingMailService', $file->getContents(), $file->getRelativePathname());
            }
        }
    }
}
