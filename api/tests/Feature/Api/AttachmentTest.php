<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailClientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function attachment(array $attrs = []): Attachment
    {
        $account = Account::query()->create([
            'provider' => 'gmail', 'email' => 'me@example.com', 'access_token' => 'at', 'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(), 'scopes' => [Account::SCOPE_GMAIL_MODIFY], 'initial_sync_done' => true, 'history_id' => '1',
        ]);
        $message = Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'gm-1', 'subject' => 'x', 'to_recipients' => [], 'cc_recipients' => [],
            'bcc_recipients' => [], 'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'received_at' => now(), 'sent_at' => now(),
        ]);

        return Attachment::query()->create(array_merge([
            'message_id' => $message->id, 'provider_attachment_id' => 'ATT-1', 'filename' => 'ponuda.pdf',
            'mime_type' => 'application/pdf', 'size' => 4, 'is_inline' => false,
        ], $attrs));
    }

    public function test_pdf_is_fetched_once_cached_and_previewable_inline(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment();

        $client = Mockery::mock(GmailClient::class);
        $client->shouldReceive('getAttachment')->once()->with('gm-1', 'ATT-1')->andReturn('%PDF');
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')->andReturn($client));

        $res = $this->get("/api/attachments/{$attachment->id}");
        $res->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline; filename=ponuda.pdf', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('frame-ancestors', $res->headers->get('Content-Security-Policy'));
        $this->assertSame('%PDF', file_get_contents($res->getFile()->getPathname()));

        Storage::disk('local')->assertExists("attachments/{$attachment->message->account_id}/{$attachment->message_id}/{$attachment->id}");

        // second request served from the local cache (mock allows exactly one Gmail call)
        $res = $this->get("/api/attachments/{$attachment->id}?download=1");
        $res->assertOk();
        $this->assertStringContainsString('attachment; filename=ponuda.pdf', $res->headers->get('Content-Disposition'));
    }

    public function test_non_previewable_types_are_forced_to_download_as_octet_stream(): void
    {
        Storage::fake('local');
        $attachment = $this->attachment(['filename' => 'page.html', 'mime_type' => 'text/html']);

        $client = Mockery::mock(GmailClient::class);
        $client->shouldReceive('getAttachment')->once()->andReturn('<script>alert(1)</script>');
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')->andReturn($client));

        $res = $this->get("/api/attachments/{$attachment->id}");
        $res->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment;', $res->headers->get('Content-Disposition'));
    }

    public function test_message_detail_exposes_attachment_urls(): void
    {
        $attachment = $this->attachment();

        $json = $this->getJson("/api/messages/{$attachment->message_id}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.previewable', true)
            ->json('data.attachments.0');

        $this->assertStringEndsWith("/api/attachments/{$attachment->id}", $json['url']);
        $this->assertStringEndsWith("/api/attachments/{$attachment->id}?download=1", $json['download_url']);
    }
}
