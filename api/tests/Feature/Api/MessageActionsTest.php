<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Message;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageActionsTest extends TestCase
{
    use RefreshDatabase;

    private function confirmed(array $payload, string $key): array
    {
        return $payload + ['confirm_send' => true, 'idempotency_key' => $key];
    }

    private function account(array $scopes): Account
    {
        return $this->makeAccount(['display_name' => 'Me', 'scopes' => $scopes]);
    }

    private function message(Account $account, array $attrs = []): Message
    {
        return Message::query()->create(array_merge([
            'account_id' => $account->id,
            'provider_message_id' => 'gm-1',
            'thread_id' => 'thr-1',
            'message_id_header' => '<orig@example.com>',
            'subject' => 'Ponuda',
            'from_name' => 'Ana',
            'from_email' => 'ana@example.com',
            'to_recipients' => [['name' => 'Me', 'email' => 'me@example.com']],
            'cc_recipients' => [],
            'bcc_recipients' => [],
            'reply_to' => [],
            'snippet' => 'hi',
            'body_text' => "Bok,\nevo ponude.",
            'body_html' => '<p>Bok,<br>evo ponude.</p>',
            'headers' => [],
            'label_ids' => ['INBOX', 'UNREAD'],
            'is_unread' => true,
            'is_starred' => false,
            'has_attachments' => false,
            'size_estimate' => 10,
            'sent_at' => now(),
            'received_at' => now(),
        ], $attrs));
    }

    public function test_mark_read_is_local_only_without_modify_scope(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_READONLY]);
        $message = $this->message($account);
        $this->noGmail();

        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => false])
            ->assertOk()
            ->assertJsonPath('data.is_unread', false)
            ->assertJsonPath('remote', 'skipped');

        $this->assertFalse($message->fresh()->is_unread);
        $this->assertNotContains('UNREAD', $message->fresh()->label_ids);
    }

    public function test_mark_read_and_unread_are_mirrored_to_gmail(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_MODIFY]);
        $message = $this->message($account);
        $client = $this->fakeGmail();

        $client->shouldReceive('modifyLabels')->once()->with('gm-1', [], ['UNREAD'])->andReturn(new GmailMessage);
        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => false])
            ->assertOk()
            ->assertJsonPath('remote', 'ok')
            ->assertJsonPath('data.is_unread', false);

        $client->shouldReceive('modifyLabels')->once()->with('gm-1', ['UNREAD'], [])->andReturn(new GmailMessage);
        $this->patchJson("/api/messages/{$message->id}", ['is_unread' => true])
            ->assertOk()
            ->assertJsonPath('data.is_unread', true);

        $this->assertContains('UNREAD', $message->fresh()->label_ids);
    }

    public function test_reply_is_refused_without_modify_scope(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_READONLY]);
        $message = $this->message($account);

        $this->postJson("/api/messages/{$message->id}/reply", $this->confirmed(
            ['to' => ['ana@example.com'], 'body' => 'Hvala'],
            '91e73677-9fcc-408a-a5e0-65355a7c4b11',
        ))
            ->assertStatus(409)
            ->assertJsonPath('needs_reauth', true);
    }

    public function test_reply_sends_threaded_message_and_stores_sent_copy(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_MODIFY]);
        $message = $this->message($account);
        $client = $this->fakeGmail();

        $captured = null;
        $client->shouldReceive('sendRaw')->once()
            ->withArgs(function (string $raw, ?string $threadId) use (&$captured) {
                $captured = $raw;

                return $threadId === 'thr-1';
            })
            ->andReturn(new GmailMessage(['id' => 'gm-sent', 'threadId' => 'thr-1']));

        $client->shouldReceive('getMessage')->once()->with('gm-sent', 'full')
            ->andReturn($this->gmailMessage('gm-sent', ['SENT'], 'Re: Ponuda', 'Hvala', 'Me <me@example.com>', 'thr-1'));

        $this->postJson("/api/messages/{$message->id}/reply", $this->confirmed(
            ['to' => ['ana@example.com'], 'body' => "Hvala,\nvidimo se."],
            'd454ca53-236a-4600-bf93-3df91bc615e7',
        ))
            ->assertStatus(201)
            ->assertJsonPath('data.provider_message_id', 'gm-sent')
            ->assertJsonPath('data.subject', 'Re: Ponuda');

        $this->assertStringContainsString('Subject: Re: Ponuda', $captured);
        $this->assertStringContainsString('In-Reply-To: <orig@example.com>', $captured);
        $this->assertStringContainsString('References: <orig@example.com>', $captured);
        $this->assertStringContainsString('To: ana@example.com', $captured);
        $this->assertStringContainsString('> evo ponude.', $captured);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'gm-sent', 'account_id' => $account->id]);
    }

    public function test_forward_starts_new_thread_and_includes_original(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_MODIFY]);
        $message = $this->message($account);
        $client = $this->fakeGmail();

        $captured = null;
        $client->shouldReceive('sendRaw')->once()
            ->withArgs(function (string $raw, ?string $threadId) use (&$captured) {
                $captured = $raw;

                return $threadId === null;
            })
            ->andReturn(new GmailMessage(['id' => 'gm-fwd', 'threadId' => 'thr-9']));
        $client->shouldReceive('getMessage')->once()->andReturn(null);

        // Sent copy could not be fetched -> 500 with a clear message, but Gmail send happened.
        $this->postJson("/api/messages/{$message->id}/forward", $this->confirmed(
            ['to' => ['pero@example.com'], 'body' => 'FYI'],
            '7ba8192d-b4a5-463d-988e-48709a1fd552',
        ))
            ->assertStatus(500);

        $this->assertStringContainsString('Subject: Fwd: Ponuda', $captured);
        $this->assertStringContainsString('Forwarded message', $captured);
        $this->assertStringContainsString('evo ponude.', $captured);
    }

    public function test_empty_body_is_allowed_for_forward_reply_and_local_drafts(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_MODIFY]);
        $message = $this->message($account);
        $client = $this->fakeGmail();
        $client->shouldReceive('sendRaw')->once()->andReturn(new GmailMessage(['id' => 'gm-fwd', 'threadId' => 't']));
        $client->shouldReceive('getMessage')->once()->andReturn($this->gmailMessage('gm-fwd', ['SENT'], 'Fwd: Ponuda', 'x'));

        // The browser sends "" for an untouched note; the framework turns it into null.
        $this->postJson("/api/messages/{$message->id}/forward", $this->confirmed(
            ['to' => ['pero@example.com'], 'body' => ''],
            '2f0c4a1e-5b6d-4e7f-8a9b-0c1d2e3f4a5b',
        ))->assertStatus(201);

        $this->putJson("/api/messages/{$message->id}/local-draft", ['kind' => 'reply', 'body' => ''])->assertOk()->assertJsonPath('data.body', '');

        // ... but a missing body key is still a validation error.
        $this->postJson("/api/messages/{$message->id}/reply", $this->confirmed(['to' => ['a@b.c']], '55118cf2-010e-4f80-a697-d089ab80d7fb'))
            ->assertStatus(422)->assertJsonValidationErrors(['body']);
    }

    public function test_compose_validation(): void
    {
        $account = $this->account([Account::SCOPE_GMAIL_MODIFY]);
        $message = $this->message($account);

        $this->postJson("/api/messages/{$message->id}/reply", $this->confirmed(
            ['to' => [], 'body' => 'x'],
            '71716b86-e6ee-42b7-956a-f6263ecb66f5',
        ))->assertStatus(422);
        $this->postJson("/api/messages/{$message->id}/reply", $this->confirmed(
            ['to' => ['a@b.c']],
            '55118cf2-010e-4f80-a697-d089ab80d7fb',
        ))->assertStatus(422);
    }
}
