<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailClientResolver;
use Google\Service\Gmail\Draft as GmailDraft;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class NewMessageTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_KEY = '64bd9f89-f266-4ac3-9950-0d40328c6d74';

    private function confirmed(array $payload, string $key = self::SEND_KEY): array
    {
        return $payload + ['confirm_send' => true, 'idempotency_key' => $key];
    }

    private function account(): Account
    {
        return $this->makeAccount(['display_name' => 'Davor']);
    }

    private function sentCopy(string $id, array $labels, string $subject, string $body): GmailMessage
    {
        return $this->gmailMessage($id, $labels, $subject, $body, 'Davor <me@example.com>');
    }

    public function test_new_message_is_sent_without_thread_and_stored(): void
    {
        $account = $this->account();
        $client = $this->fakeGmail();

        $captured = null;
        $client->shouldReceive('sendRaw')->once()
            ->withArgs(function (string $raw, ?string $threadId) use (&$captured) {
                $captured = $raw;

                return $threadId === null;
            })
            ->andReturn(new GmailMessage(['id' => 'gm-new', 'threadId' => 't-new']));
        $client->shouldReceive('getMessage')->once()->with('gm-new', 'full')->andReturn($this->sentCopy('gm-new', ['SENT'], 'Pozdrav', 'Bok Ana'));

        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->confirmed(['to' => ['Ana <ana@example.com>'], 'subject' => 'Pozdrav', 'body' => "Bok Ana,\nvidimo se."]))
            ->assertStatus(201)
            ->assertJsonPath('data.provider_message_id', 'gm-new');

        $this->assertStringContainsString('To: Ana <ana@example.com>', $captured);
        $this->assertStringContainsString('Subject: Pozdrav', $captured);
        $this->assertStringContainsString('From: Davor <me@example.com>', $captured);
        $this->assertStringNotContainsString('In-Reply-To', $captured);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'gm-new']);
    }

    public function test_new_message_requires_recipient_but_draft_does_not(): void
    {
        $account = $this->account();
        $client = $this->fakeGmail();

        $this->postJson("/api/accounts/{$account->id}/messages/send", $this->confirmed(['to' => [], 'subject' => 'x', 'body' => 'y']))->assertStatus(422);

        $client->shouldReceive('createDraft')->once()->withArgs(fn ($raw, $thread) => $thread === null)
            ->andReturn(new GmailDraft(['id' => 'd', 'message' => ['id' => 'gm-d', 'threadId' => 't-d']]));
        $client->shouldReceive('getMessage')->once()->andReturn($this->sentCopy('gm-d', ['DRAFT'], 'x', 'y'));

        $this->postJson("/api/accounts/{$account->id}/messages/draft", ['to' => [], 'subject' => 'x', 'body' => 'y'])
            ->assertStatus(201)
            ->assertJsonPath('data.label_ids', ['DRAFT']);
    }

    public function test_send_requires_explicit_confirmation_and_idempotency_key(): void
    {
        $account = $this->account();
        $this->noGmail();

        $this->postJson("/api/accounts/{$account->id}/messages/send", [
            'to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['confirm_send', 'idempotency_key']);
    }

    public function test_repeated_send_request_returns_the_same_message_without_sending_twice(): void
    {
        $account = $this->account();
        $client = Mockery::mock(GmailClient::class);
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')->once()->andReturn($client));

        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-once', 'threadId' => 't-once']));
        $client->shouldReceive('getMessage')->once()->with('gm-once', 'full')->andReturn($this->sentCopy('gm-once', ['SENT'], 'Pozdrav', 'Bok'));

        $payload = $this->confirmed(['to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok']);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)
            ->assertCreated()
            ->assertJsonPath('data.provider_message_id', 'gm-once');
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)
            ->assertCreated()
            ->assertJsonPath('data.provider_message_id', 'gm-once');

        $this->assertDatabaseCount('outgoing_mail_attempts', 1);
    }

    public function test_uncertain_send_is_never_automatically_retried(): void
    {
        $account = $this->account();
        $client = Mockery::mock(GmailClient::class);
        $this->mock(GmailClientResolver::class, fn (MockInterface $m) => $m->shouldReceive('for')->once()->andReturn($client));
        $client->shouldReceive('sendRaw')->once()->andThrow(new \RuntimeException('connection dropped'));

        $payload = $this->confirmed(['to' => ['ana@example.com'], 'subject' => 'Pozdrav', 'body' => 'Bok']);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)->assertStatus(500);
        $this->postJson("/api/accounts/{$account->id}/messages/send", $payload)
            ->assertStatus(409)
            ->assertJsonPath('duplicate_prevented', true);
    }

    public function test_ai_drafts_new_mail_from_notes_using_style_examples_for_recipient(): void
    {
        $account = $this->account();
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'qwen3:8b', true))->save();

        Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'sent-1', 'subject' => 'Re: Prošli put', 'from_name' => 'Davor', 'from_email' => 'me@example.com',
            'to_recipients' => [['name' => 'Ana', 'email' => 'ana@example.com']], 'cc_recipients' => [], 'bcc_recipients' => [], 'reply_to' => [], 'headers' => [],
            'label_ids' => ['SENT'], 'body_text' => "Bok Ana,\n\nmože u tjednu nakon.\n\nLp, Davor", 'received_at' => now(), 'sent_at' => now(),
        ]);

        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => 'Gotov mail']]),
        ]);

        $this->postJson("/api/accounts/{$account->id}/ai/draft", ['to' => ['ana@example.com'], 'subject' => 'Sastanak', 'current_draft' => '- četvrtak 10h\n- donesi ugovor'])
            ->assertOk()
            ->assertJsonPath('data.text', 'Gotov mail');

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/api/chat')) {
                return false;
            }
            $system = $r['messages'][0]['content'];
            $user = $r['messages'][1]['content'];

            return str_contains($system, 'complete new e-mail')
                && str_contains($user, 'How the user previously wrote to ana@example.com')
                && str_contains($user, 'može u tjednu nakon')
                && str_contains($user, 'New e-mail to write')
                && str_contains($user, 'donesi ugovor');
        });

        // Nothing to go on -> clear error instead of a hallucinated mail.
        $this->postJson("/api/accounts/{$account->id}/ai/draft", ['to' => ['ana@example.com']])->assertStatus(503);
    }
}
