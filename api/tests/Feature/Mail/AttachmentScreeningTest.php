<?php

namespace Tests\Feature\Mail;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Sync\GmailSyncService;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dangerous attachments are flagged from metadata during sync (nothing is
 * downloaded), the message is locked until the user acknowledges the risk,
 * and the attachments themselves can never be previewed, downloaded or forwarded.
 */
class AttachmentScreeningTest extends TestCase
{
    use RefreshDatabase;

    private function withAttachments(string $id, array $parts): GmailMessage
    {
        return new GmailMessage([
            'id' => $id, 'threadId' => 't-'.$id, 'labelIds' => ['INBOX', 'UNREAD'], 'internalDate' => (string) now()->getTimestampMs(), 'snippet' => 'hi',
            'payload' => ['mimeType' => 'multipart/mixed', 'headers' => [['name' => 'From', 'value' => 'Ana <ana@example.com>'], ['name' => 'Subject', 'value' => 'Faktura']],
                'parts' => array_merge([['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('Pogledaj privitak'), '+/', '-_'), '=')]]],
                    array_map(fn ($p, $i) => ['partId' => (string) ($i + 1), 'mimeType' => $p[1], 'filename' => $p[0], 'body' => ['attachmentId' => 'att-'.($i + 1), 'size' => 1234]], $parts, array_keys($parts)))],
        ]);
    }

    private function sync(Account $account, GmailMessage $gmailMessage): Message
    {
        app(GmailSyncService::class)->store($account, $gmailMessage);

        return Message::query()->where('provider_message_id', $gmailMessage->getId())->firstOrFail();
    }

    public function test_sync_flags_from_metadata_only_and_never_downloads(): void
    {
        $account = $this->makeAccount();
        $client = $this->fakeGmail();
        $client->shouldNotReceive('getAttachment');

        $risky = $this->sync($account, $this->withAttachments('gm-1', [['ponuda.pdf', 'application/pdf'], ['Faktura.pdf.exe', 'application/octet-stream']]));
        $safe = $this->sync($account, $this->withAttachments('gm-2', [['slika.jpg', 'image/jpeg']]));

        $this->assertTrue($risky->fresh()->is_risky);
        $this->assertFalse($safe->fresh()->is_risky);
        $this->assertSame([null, '".exe" files run code when opened.'], $risky->attachments()->orderBy('id')->pluck('risk_reason')->all());
        $this->assertSame([null, 'danger'], $risky->attachments()->orderBy('id')->pluck('risk_level')->all());

        $this->getJson("/api/accounts/{$account->id}/messages")->assertOk()
            ->assertJsonPath('data.0.is_risky', false)
            ->assertJsonPath('data.1.is_risky', true)
            ->assertJsonPath('data.1.risk_acknowledged', false);
    }

    public function test_message_is_locked_until_acknowledged_and_attachments_stay_blocked_forever(): void
    {
        $account = $this->makeAccount();
        $this->fakeGmail()->shouldNotReceive('getAttachment');
        $message = $this->sync($account, $this->withAttachments('gm-1', [['ponuda.pdf', 'application/pdf'], ['setup.exe', 'application/x-msdownload']]));
        [$pdf, $exe] = $message->attachments()->orderBy('id')->get();

        $this->getJson("/api/messages/{$message->id}")->assertStatus(423)->assertJsonPath('risk_locked', true);

        $this->postJson("/api/messages/{$message->id}/acknowledge-risk")->assertOk()->assertJsonPath('data.risk_acknowledged', true);
        $detail = $this->getJson("/api/messages/{$message->id}")->assertOk()->assertJsonPath('data.is_risky', true)->json('data');

        // The clean attachment is reachable, the executable has no URL at all ...
        $this->assertNotNull($detail['attachments'][0]['url']);
        $this->assertNull($detail['attachments'][1]['url']);
        $this->assertNull($detail['attachments'][1]['download_url']);
        $this->assertFalse($detail['attachments'][1]['previewable']);
        $this->assertStringContainsString('executable', $detail['attachments'][1]['risk']);

        // ... and guessing its id does not help either.
        $this->getJson("/api/attachments/{$exe->id}")->assertStatus(403)->assertJsonPath('blocked', true);
        $this->getJson("/api/attachments/{$exe->id}?download=1")->assertStatus(403);
    }

    public function test_content_that_contradicts_the_declared_type_is_blocked_on_download(): void
    {
        Storage::fake('local');
        $account = $this->makeAccount();
        $client = $this->fakeGmail();
        $message = $this->sync($account, $this->withAttachments('gm-1', [['izvod.pdf', 'application/pdf']]));
        $attachment = $message->attachments()->firstOrFail();
        $this->assertFalse($message->fresh()->is_risky);

        // The mail said PDF; Gmail hands us a Windows executable.
        $client->shouldReceive('getAttachment')->once()->andReturn("MZ\x90\x00\x03 fake exe");
        $this->getJson("/api/attachments/{$attachment->id}")->assertStatus(403)->assertJsonPath('blocked', true);

        $this->assertStringContainsString('executable', $attachment->fresh()->risk_reason);
        $this->assertTrue($message->fresh()->is_risky);
        Storage::disk('local')->assertMissing("attachments/{$account->id}/{$message->id}/{$attachment->id}");

        // Second try: refused before Gmail is even asked.
        $this->getJson("/api/attachments/{$attachment->id}")->assertStatus(403);
    }

    public function test_forward_never_carries_blocked_attachments(): void
    {
        $account = $this->makeAccount(['display_name' => 'Me']);
        $client = $this->fakeGmail();
        $message = $this->sync($account, $this->withAttachments('gm-1', [['ponuda.pdf', 'application/pdf'], ['setup.exe', 'application/x-msdownload']]));
        $message->forceFill(['risk_acknowledged_at' => now()])->save();

        $client->shouldReceive('getAttachment')->once()->with('gm-1', 'att-1')->andReturn('%PDF-1.7 bytes');
        $client->shouldNotReceive('getAttachment')->with('gm-1', 'att-2');
        $captured = null;
        $client->shouldReceive('sendRaw')->once()->withArgs(function (string $raw) use (&$captured) {
            $captured = $raw;

            return true;
        })->andReturn(new GmailMessage(['id' => 'gm-fwd', 'threadId' => 't']));
        $client->shouldReceive('getMessage')->once()->andReturn($this->gmailMessage('gm-fwd', ['SENT'], 'Fwd: Faktura', 'x'));

        $this->postJson("/api/messages/{$message->id}/forward", [
            'to' => ['pero@example.com'], 'body' => 'FYI', 'include_attachments' => true,
            'confirm_send' => true, 'idempotency_key' => '6f1d2c3b-4a5e-4f60-9b7c-8d9e0f1a2b3c',
        ])->assertStatus(201);

        $this->assertStringContainsString('ponuda.pdf', $captured);
        $this->assertStringNotContainsString('setup.exe', $captured);
    }

    public function test_only_explicit_user_actions_can_download_attachment_bytes(): void
    {
        // Tripwire: the sync/queue/scheduler code must never call getAttachment().
        $callers = [];
        foreach (File::allFiles(app_path()) as $file) {
            $src = $file->getContents();
            if (str_contains($src, '->getAttachment(') || str_contains($src, '->fetchAttachment(')) {
                $callers[] = $file->getRelativePathname();
            }
        }
        sort($callers);
        // Adapters wrap the network call; only the attachment service (preview/download) and the
        // outgoing service (forward) ever ask a provider for attachment bytes.
        $this->assertSame(['Services/Mail/AttachmentService.php', 'Services/Mail/OutgoingMailService.php', 'Services/Mail/Providers/GmailProvider.php'], $callers);
        foreach (['Jobs', 'Console', 'Services/Sync'] as $dir) {
            foreach (File::allFiles(app_path($dir)) as $file) {
                $this->assertStringNotContainsString('ensureLocal', $file->getContents(), $file->getRelativePathname());
                $this->assertStringNotContainsString('etchAttachment', $file->getContents(), $file->getRelativePathname());
                $this->assertStringNotContainsString('getAttachment', $file->getContents(), $file->getRelativePathname());
            }
        }
    }

    public function test_archives_are_caution_open_normally_and_can_be_downloaded(): void
    {
        Storage::fake('local');
        $account = $this->makeAccount();
        $client = $this->fakeGmail();
        $message = $this->sync($account, $this->withAttachments('gm-1', [['dokumentacija.rar', 'application/x-compressed']]));
        $attachment = $message->attachments()->firstOrFail();

        $this->assertFalse($message->fresh()->is_risky);
        $this->assertTrue($message->fresh()->has_caution);
        $this->getJson("/api/accounts/{$account->id}/messages")->assertOk()->assertJsonPath('data.0.has_caution', true)->assertJsonPath('data.0.is_risky', false);

        // Opens without acknowledgement; the archive keeps its URLs but is labelled.
        $detail = $this->getJson("/api/messages/{$message->id}")->assertOk()->json('data');
        $this->assertSame('caution', $detail['attachments'][0]['risk_level']);
        $this->assertNotNull($detail['attachments'][0]['download_url']);

        // Download works (the UI asks for confirmation first) and the content is still sniffed.
        $client->shouldReceive('getAttachment')->once()->andReturn("Rar!\x1a\x07\x01\x00 bytes");
        $this->getJson("/api/attachments/{$attachment->id}?download=1")->assertOk();
    }

    public function test_rescan_command_flags_existing_mail(): void
    {
        $account = $this->makeAccount();
        $message = Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'old', 'subject' => 'x', 'to_recipients' => [], 'cc_recipients' => [],
            'bcc_recipients' => [], 'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'received_at' => now(), 'sent_at' => now(), 'has_attachments' => true,
        ]);
        Attachment::query()->create(['message_id' => $message->id, 'provider_attachment_id' => 'a', 'filename' => 'virus.scr', 'mime_type' => 'application/octet-stream', 'size' => 1]);

        $this->artisan('sovera:rescan-attachments')->expectsOutputToContain('1 dangerous attachment(s) in 1 message(s) (blocked)')->assertSuccessful();
        $this->assertTrue($message->fresh()->is_risky);
    }
}
