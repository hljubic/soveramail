<?php

namespace Tests\Feature\Imap;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Models\Message;
use App\Services\Imap\ImapSession;
use App\Services\Imap\ImapSessionFactory;
use App\Services\Imap\ImapSyncService;
use App\Services\Mail\AttachmentService;
use App\Services\Mail\Providers\MailProvider;
use App\Services\Mail\Providers\MailProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

/**
 * End-to-end against a real IMAP/SMTP server (GreenMail), not a fake:
 *
 *   docker compose --profile mailtest up -d greenmail
 *   docker compose run --rm api php artisan test --filter=ImapLiveServer
 *
 * Skipped when no server is reachable, so `make test` stays green without it.
 */
class ImapLiveServerTest extends TestCase
{
    use RefreshDatabase;

    private const USER = 'me@sovera.test';

    /** GreenMail logs in with the local part, so this also covers "login differs from address". */
    private const LOGIN = 'me';

    private const PASSWORD = 'app-password';

    private string $host;

    private int $imapPort;

    private int $smtpPort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = (string) (getenv('IMAP_TEST_HOST') ?: 'greenmail');
        $this->imapPort = (int) (getenv('IMAP_TEST_IMAP_PORT') ?: 3143);
        $this->smtpPort = (int) (getenv('IMAP_TEST_SMTP_PORT') ?: 3025);

        $socket = @fsockopen($this->host, $this->imapPort, $errno, $error, 2);
        if ($socket === false) {
            $this->markTestSkipped("No IMAP server at {$this->host}:{$this->imapPort} (docker compose --profile mailtest up -d greenmail).");
        }
        fclose($socket);

        $this->resetMailbox(self::LOGIN, self::PASSWORD);
    }

    /** @return array{imap: array, smtp: array} */
    private function credentials(string $login = self::LOGIN, string $password = self::PASSWORD): array
    {
        return [
            'imap' => ['host' => $this->host, 'port' => $this->imapPort, 'encryption' => 'none', 'username' => $login, 'password' => $password],
            'smtp' => ['host' => $this->host, 'port' => $this->smtpPort, 'encryption' => 'none', 'username' => $login, 'password' => $password],
        ];
    }

    private function account(): Account
    {
        return $this->makeAccount([
            'provider' => Account::PROVIDER_IMAP, 'email' => self::USER, 'display_name' => 'Me',
            'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null, 'scopes' => [],
            'credentials' => $this->credentials(), 'initial_sync_done' => false, 'history_id' => null,
        ]);
    }

    private function imapSession(string $login = self::LOGIN, string $password = self::PASSWORD): ImapSession
    {
        return app(ImapSessionFactory::class)->openWith($this->credentials($login, $password)['imap']);
    }

    /** Start every test from a known state: only INBOX/Sent/Drafts, all empty. */
    private function resetMailbox(string $login, string $password): void
    {
        $client = (new ClientManager)->make([
            'host' => $this->host, 'port' => $this->imapPort, 'encryption' => false, 'validate_cert' => false,
            'username' => $login, 'password' => $password, 'protocol' => 'imap',
        ]);
        $client->connect();
        $protocol = $client->getConnection();

        foreach (['INBOX', 'Sent', 'Drafts'] as $folder) {
            try {
                $protocol->createFolder($folder);
            } catch (Throwable) {
                // already there
            }
            try {
                $protocol->selectFolder($folder);
                $uids = (array) $protocol->search(['ALL'])->data();
                if ($uids !== []) {
                    // Only the test fixture ever deletes here; the app itself never does.
                    $protocol->store(['\\Deleted'], min($uids), max($uids), '+');
                    $protocol->expunge();
                }
            } catch (Throwable) {
                // empty or missing folder
            }
        }
        $client->disconnect();
    }

    private function raw(string $from, string $to, string $subject, string $body, string $messageId, ?string $inReplyTo = null): string
    {
        $headers = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\nMessage-ID: <{$messageId}>\r\n"
            ."Date: Thu, 10 Sep 2026 10:00:00 +0200\r\n";
        if ($inReplyTo) {
            $headers .= "In-Reply-To: <{$inReplyTo}>\r\nReferences: <{$inReplyTo}>\r\n";
        }

        return $headers."MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$body}\r\n";
    }

    private function rawWithPdf(string $subject, string $messageId, string $filename, string $bytes): string
    {
        return "From: Ana <ana@sovera.test>\r\nTo: ".self::USER."\r\nSubject: {$subject}\r\nMessage-ID: <{$messageId}>\r\n"
            ."Date: Thu, 10 Sep 2026 11:00:00 +0200\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"B1\"\r\n\r\n"
            ."--B1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nU privitku je ponuda.\r\n"
            ."--B1\r\nContent-Type: application/pdf; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bytes))."--B1--\r\n";
    }

    public function test_full_and_incremental_sync_against_a_real_server(): void
    {
        $session = $this->imapSession();
        $session->append('INBOX', $this->raw('Ana <ana@sovera.test>', self::USER, 'Ponuda', 'Šaljem ponudu, molim potvrdu.', 'a1@sovera.test'), []);
        $session->append('INBOX', $this->raw('Pero <pero@sovera.test>', self::USER, 'Ručak', 'Idemo u 13?', 'a2@sovera.test'), ['\\Seen', '\\Flagged']);
        $session->append('Sent', $this->raw('Me <'.self::USER.'>', 'ana@sovera.test', 'Re: Ponuda', 'Hvala, javljam se.', 's1@sovera.test', 'a1@sovera.test'), ['\\Seen']);
        $session->close();

        $account = $this->account();
        $stats = app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL, 50);

        $this->assertSame(3, $stats['added']);
        $this->assertSame('idle', $account->fresh()->sync_status);

        $stored = Message::query()->where('account_id', $account->id)->get()->keyBy('subject');
        $this->assertSame(['INBOX', 'UNREAD'], $stored['Ponuda']->label_ids);
        $this->assertSame(['INBOX', 'STARRED'], $stored['Ručak']->label_ids);
        $this->assertSame(['SENT'], $stored['Re: Ponuda']->label_ids);
        $this->assertSame('Šaljem ponudu, molim potvrdu.', trim((string) $stored['Ponuda']->body_text));
        $this->assertSame('ana@sovera.test', $stored['Ponuda']->from_email);
        // The reply and the original share a thread (References root).
        $this->assertSame($stored['Ponuda']->thread_id, $stored['Re: Ponuda']->thread_id);

        // Second run: nothing new, no duplicates.
        $stats = app(MailProviders::class)->for($account->fresh())->sync($account->fresh());
        $this->assertSame(['added' => 0, 'deleted' => 0], ['added' => $stats['added'], 'deleted' => $stats['deleted']]);
        $this->assertDatabaseCount('messages', 3);

        // New mail arrives -> incremental picks it up by UID.
        $session = $this->imapSession();
        $session->append('INBOX', $this->raw('Boss <boss@sovera.test>', self::USER, 'Plan', 'Plan za Q4.', 'a3@sovera.test'), []);
        $session->close();

        $stats = app(MailProviders::class)->for($account->fresh())->sync($account->fresh());
        $this->assertSame(1, $stats['added']);
        $this->assertDatabaseCount('messages', 4);
        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_syncing_never_changes_anything_on_the_server(): void
    {
        $session = $this->imapSession();
        $session->append('INBOX', $this->raw('Ana <ana@sovera.test>', self::USER, 'Nepročitano', 'Ovo mora ostati nepročitano.', 'r1@sovera.test'), []);
        $session->close();

        $account = $this->account();
        foreach ([MailProvider::MODE_FULL, MailProvider::MODE_INCREMENTAL, MailProvider::MODE_BACKFILL] as $mode) {
            app(MailProviders::class)->for($account->fresh())->sync($account->fresh(), $mode, 50);
        }

        // Reading a mailbox must not touch it: no \Seen (a plain RFC822 fetch would
        // set it), nothing deleted, nothing moved.
        $check = $this->imapSession();
        $uids = $check->search('INBOX', ['ALL']);
        $this->assertCount(1, $uids);
        $this->assertSame([], $check->flags('INBOX', $uids)[$uids[0]]);
        $this->assertSame([], $check->search('Sent', ['ALL']));
        $this->assertSame([], $check->search('Drafts', ['ALL']));
        $check->close();

        $this->assertTrue(Message::query()->where('subject', 'Nepročitano')->value('is_unread'));
    }

    public function test_sending_delivers_over_smtp_and_appends_to_sent(): void
    {
        $account = $this->account();
        app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL, 50);

        $this->postJson("/api/accounts/{$account->id}/messages/send", [
            'to' => ['ana@sovera.test'], 'subject' => 'Pozdrav iz Sovere', 'body' => "Bok Ana,\n\nradi li ovo?\n\nLp",
            'confirm_send' => true, 'idempotency_key' => 'b4f1c0de-1111-4222-8333-444455556666',
        ])->assertCreated()->assertJsonPath('data.label_ids', ['SENT']);

        // 1. The recipient really received it.
        $ana = $this->imapSession('ana', 'pw');
        $uids = $ana->search('INBOX', ['ALL']);
        $this->assertNotEmpty($uids, 'the message never reached the recipient');
        $delivered = $ana->fetch('INBOX', [max($uids)])[max($uids)]['raw'];
        $ana->close();
        $this->assertStringContainsString('Subject: Pozdrav iz Sovere', $delivered);
        $this->assertStringContainsString('radi li ovo?', quoted_printable_decode($delivered));

        // 2. A copy is in Sent on the server, and locally.
        $me = $this->imapSession();
        $sent = $me->search('Sent', ['ALL']);
        $me->close();
        $this->assertCount(1, $sent);
        $this->assertDatabaseHas('messages', ['account_id' => $account->id, 'subject' => 'Pozdrav iz Sovere']);

        // 3. The same idempotency key never sends twice.
        $this->postJson("/api/accounts/{$account->id}/messages/send", [
            'to' => ['ana@sovera.test'], 'subject' => 'Pozdrav iz Sovere', 'body' => "Bok Ana,\n\nradi li ovo?\n\nLp",
            'confirm_send' => true, 'idempotency_key' => 'b4f1c0de-1111-4222-8333-444455556666',
        ])->assertCreated();
        $me = $this->imapSession();
        $this->assertCount(1, $me->search('Sent', ['ALL']));
        $me->close();
    }

    public function test_drafts_flags_and_attachments_round_trip(): void
    {
        $session = $this->imapSession();
        $session->append('INBOX', $this->rawWithPdf('Ponuda s privitkom', 'p1@sovera.test', 'ponuda.pdf', '%PDF-1.7 sovera test'), []);
        $session->close();

        $account = $this->account();
        app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL, 50);
        $message = Message::query()->where('subject', 'Ponuda s privitkom')->firstOrFail();

        // Attachment metadata came from the MIME structure, bytes only on demand.
        $attachment = $message->attachments()->firstOrFail();
        $this->assertSame(['ponuda.pdf', 'application/pdf'], [$attachment->filename, $attachment->mime_type]);
        Storage::fake('local');
        $this->getJson("/api/attachments/{$attachment->id}?download=1")->assertOk();
        $this->assertSame('%PDF-1.7 sovera test', file_get_contents(app(AttachmentService::class)->ensureLocal($attachment)));

        // Draft: stored in Drafts with \Draft, never sent.
        $this->postJson("/api/messages/{$message->id}/draft", ['kind' => 'reply', 'to' => ['ana@sovera.test'], 'body' => 'Skica odgovora'])->assertCreated();
        $me = $this->imapSession();
        $drafts = $me->search('Drafts', ['ALL']);
        $this->assertCount(1, $drafts);
        $this->assertContains('\\Draft', $me->flags('Drafts', $drafts)[$drafts[0]]);
        $this->assertSame([], $me->search('Sent', ['ALL']));

        // Read/star state is mirrored as \Seen / \Flagged on the server.
        [$path, , $uid] = ImapSyncService::parseId($message->provider_message_id);
        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => false])->assertOk()->assertJsonPath('remote', 'ok');
        $this->patchJson("/api/messages/{$message->id}", ['is_starred' => true])->assertOk()->assertJsonPath('remote', 'ok');
        $flags = $me->flags($path, [$uid])[$uid];
        $this->assertContains('\\Seen', $flags);
        $this->assertContains('\\Flagged', $flags);

        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => true])->assertOk();
        $this->assertNotContains('\\Seen', $me->flags($path, [$uid])[$uid]);
        $me->close();
    }

    public function test_wrong_password_is_reported_as_a_reconnect_problem(): void
    {
        $account = $this->account();
        $account->forceFill(['credentials' => $this->credentials(self::LOGIN, 'not-the-password')])->save();

        $this->expectException(ReauthRequiredException::class);
        try {
            app(MailProviders::class)->for($account)->sync($account, MailProvider::MODE_FULL);
        } finally {
            $this->assertSame('needs_reauth', $account->fresh()->sync_status);
        }
    }
}
