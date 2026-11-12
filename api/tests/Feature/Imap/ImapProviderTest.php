<?php

namespace Tests\Feature\Imap;

use App\Models\Account;
use App\Models\Message;
use App\Models\OutgoingMailAttempt;
use App\Services\Mail\AttachmentService;
use App\Services\Mail\Providers\MailProvider;
use App\Services\Mail\Providers\MailProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\ImapFixtures;
use Tests\TestCase;

/**
 * Sending through SMTP keeps every guarantee of the Gmail path: one confirmed
 * send, idempotent retries, a Sent copy, drafts that never send, flags only.
 */
class ImapProviderTest extends TestCase
{
    use ImapFixtures, RefreshDatabase;

    private const KEY = '0b1c2d3e-4f50-4617-8829-3a4b5c6d7e8f';

    private function synced(): Account
    {
        $this->fakeImap();
        $account = $this->imapAccount();
        $this->imap->addFolder('INBOX')->addFolder('Sent', ['\\Sent'])->addFolder('Drafts', ['\\Drafts']);
        $this->imap->put('INBOX', 1, $this->rawWithAttachment('Ana <ana@example.com>', 'me@fastmail.com', 'Ponuda', 'U privitku.', 'in-1@example.com', 'ponuda.pdf', 'application/pdf', '%PDF-1.7 fake'), []);
        app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL, 50);

        return $account->fresh();
    }

    public function test_send_goes_through_smtp_once_lands_in_sent_and_is_idempotent(): void
    {
        $account = $this->synced();
        $payload = ['to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok Ana', 'confirm_send' => true, 'idempotency_key' => self::KEY];

        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)->assertCreated()
            ->assertJsonPath('data.label_ids', ['SENT'])
            ->assertJsonPath('data.subject', 'Pozdrav');

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame('ana@example.com', $this->smtp->sent[0]->getTo()[0]->getAddress());
        $this->assertCount(1, $this->imap->appends);
        $this->assertSame(['Sent', ['\\Seen']], [$this->imap->appends[0]['path'], $this->imap->appends[0]['flags']]);
        $this->assertStringContainsString('Subject: Pozdrav', $this->imap->appends[0]['raw']);

        // Same key again: nothing new is sent.
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)->assertCreated();
        $this->assertCount(1, $this->smtp->sent);
        $this->assertDatabaseCount('outgoing_mail_attempts', 1);
        $this->assertSame(OutgoingMailAttempt::STATUS_COMPLETE, OutgoingMailAttempt::query()->value('status'));
    }

    public function test_smtp_rejection_releases_the_key_and_a_dead_connection_keeps_it(): void
    {
        $account = $this->synced();
        $payload = ['to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok', 'confirm_send' => true, 'idempotency_key' => self::KEY];

        $this->smtp->failWith = new TransportException('550 5.1.1 User unknown', 550);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)->assertStatus(500);
        $this->assertDatabaseCount('outgoing_mail_attempts', 0);

        $this->smtp->failWith = new TransportException('Connection to smtp.fastmail.com timed out', 0);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)->assertStatus(500);
        $this->assertSame(OutgoingMailAttempt::STATUS_UNCERTAIN, OutgoingMailAttempt::query()->value('status'));
        $this->smtp->failWith = null;
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)->assertStatus(409)->assertJsonPath('duplicate_prevented', true);
        $this->assertCount(0, $this->smtp->sent);
    }

    public function test_reply_threads_and_drafts_never_send(): void
    {
        $account = $this->synced();
        $original = Message::query()->where('provider_message_id', 'INBOX::1::1')->firstOrFail();

        $this->postJson("/api/messages/{$original->id}/draft", ['kind' => 'reply', 'to' => ['ana@example.com'], 'body' => 'Skica'])->assertCreated()
            ->assertJsonPath('data.label_ids', ['DRAFT']);
        $this->assertCount(0, $this->smtp->sent);
        $this->assertSame('Drafts', $this->imap->appends[0]['path']);
        $this->assertContains('\\Draft', $this->imap->appends[0]['flags']);

        $this->postJson("/api/messages/{$original->id}/reply", ['to' => ['ana@example.com'], 'body' => 'Hvala', 'confirm_send' => true, 'idempotency_key' => self::KEY])->assertCreated();
        $this->assertCount(1, $this->smtp->sent);
        $this->assertStringContainsString('In-Reply-To: <in-1@example.com>', $this->imap->appends[1]['raw']);
        $reply = Message::query()->where('provider_message_id', 'Sent::1::1')->firstOrFail();
        $this->assertSame($original->thread_id, $reply->thread_id);
    }

    public function test_read_and_star_are_the_only_flags_touched(): void
    {
        $account = $this->synced();
        $message = Message::query()->where('provider_message_id', 'INBOX::1::1')->firstOrFail();

        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => false])->assertOk()->assertJsonPath('remote', 'ok');
        $this->patchJson("/api/messages/{$message->id}", ['is_starred' => true])->assertOk()->assertJsonPath('remote', 'ok');
        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => true])->assertOk();

        $this->assertSame([
            ['INBOX', 1, ['\\Seen'], true],
            ['INBOX', 1, ['\\Flagged'], true],
            ['INBOX', 1, ['\\Seen'], false],
        ], array_map(fn ($s) => [$s['path'], $s['uid'], $s['flags'], $s['add']], $this->imap->stores));
        $this->assertSame(['\\Flagged'], $this->imap->folders['INBOX']['messages'][1]['flags']);
    }

    public function test_attachments_are_fetched_by_part_on_demand_and_forwarded(): void
    {
        $account = $this->synced();
        $message = Message::query()->where('provider_message_id', 'INBOX::1::1')->firstOrFail();
        $attachment = $message->attachments()->firstOrFail();
        $this->assertSame('ponuda.pdf', $attachment->filename);

        $this->getJson("/api/attachments/{$attachment->id}?download=1")->assertOk();
        $this->assertSame('%PDF-1.7 fake', file_get_contents(app(AttachmentService::class)->ensureLocal($attachment)));

        $this->postJson("/api/messages/{$message->id}/forward", ['to' => ['pero@example.com'], 'body' => 'FYI', 'include_attachments' => true, 'confirm_send' => true, 'idempotency_key' => self::KEY])->assertCreated();
        $this->assertStringContainsString('ponuda.pdf', $this->smtp->sent[0]->toString());
    }

    public function test_bcc_is_kept_and_mail_another_client_put_in_sent_is_not_skipped(): void
    {
        $account = $this->synced();
        // The phone sent something since the last sync: UID 1 in Sent, unknown to us.
        $this->imap->put('Sent', 1, $this->raw('me@fastmail.com', 'pero@example.com', 'S mobitela', 'Poslano s mobitela', 'phone-1@example.com'), ['\\Seen']);

        $this->postJson("/api/accounts/{$account->id}/messages/send", [
            'to' => ['ana@example.com'], 'bcc' => ['sef@example.com'], 'subject' => 'Ponuda za Anu', 'body' => 'Bok',
            'confirm_send' => true, 'idempotency_key' => self::KEY,
        ])->assertCreated();

        $this->assertSame('sef@example.com', $this->smtp->sent[0]->getBcc()[0]->getAddress());
        $this->assertStringContainsString('Bcc: sef@example.com', $this->imap->appends[0]['raw']);

        app(MailProviders::class)->for($account->fresh())->sync($account->fresh(), MailProvider::MODE_INCREMENTAL, 50);
        $this->assertDatabaseHas('messages', ['account_id' => $account->id, 'subject' => 'S mobitela']);
        $this->assertSame(1, Message::query()->where('account_id', $account->id)->where('subject', 'Ponuda za Anu')->count()); // our own copy, once
    }

    public function test_reply_to_a_message_id_without_at_sign_still_threads(): void
    {
        $account = $this->synced();
        $original = Message::query()->where('provider_message_id', 'INBOX::1::1')->firstOrFail();
        $original->forceFill(['message_id_header' => '<1234567.legacy>'])->save();

        $this->postJson("/api/messages/{$original->id}/reply", ['to' => ['ana@example.com'], 'body' => 'Hvala', 'confirm_send' => true, 'idempotency_key' => self::KEY])->assertCreated();
        $this->assertStringContainsString('In-Reply-To: <1234567.legacy>', $this->imap->appends[0]['raw']);
    }

    public function test_a_message_with_raw_8bit_headers_and_an_epoch_date_does_not_stop_the_sync(): void
    {
        $account = $this->synced();
        // Windows-1250 bytes straight in the header (no RFC 2047), Date at the epoch.
        $subject = "Ra\xE8un za o\x9Eujak";
        $this->imap->put('INBOX', 2, $this->raw('Ana <ana@example.com>', 'me@fastmail.com', $subject, 'Tijelo', 'old-1@example.com', date: 'Thu, 01 Jan 1970 00:00:00 +0100'));
        $this->imap->put('INBOX', 3, $this->raw('Pero <pero@example.com>', 'me@fastmail.com', 'Normalna', 'Tijelo', 'ok-1@example.com'));

        app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_INCREMENTAL, 50);

        $odd = Message::query()->where('provider_message_id', 'INBOX::1::2')->firstOrFail();
        $this->assertSame('Račun za ožujak', $odd->subject);
        $this->assertNull($odd->sent_at);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'INBOX::1::3', 'subject' => 'Normalna']);
    }
}
