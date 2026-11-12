<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Message;
use Google\Service\Gmail\Draft as GmailDraft;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_can_be_saved_as_gmail_draft_without_sending(): void
    {
        $account = Account::query()->create([
            'provider' => 'gmail', 'email' => 'me@example.com', 'display_name' => 'Me', 'access_token' => 'at', 'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(), 'scopes' => [Account::SCOPE_GMAIL_MODIFY], 'initial_sync_done' => true, 'history_id' => '1',
        ]);
        $original = Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'gm-1', 'thread_id' => 'thr-1', 'message_id_header' => '<o@x>',
            'subject' => 'Ponuda', 'from_name' => 'Ana', 'from_email' => 'ana@example.com', 'to_recipients' => [], 'cc_recipients' => [],
            'bcc_recipients' => [], 'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'body_text' => 'evo', 'received_at' => now(), 'sent_at' => now(),
        ]);

        $client = $this->fakeGmail();

        $client->shouldNotReceive('sendRaw');
        $client->shouldReceive('createDraft')->once()
            ->withArgs(fn (string $raw, ?string $threadId) => $threadId === 'thr-1' && str_contains($raw, 'Subject: Re: Ponuda') && str_contains($raw, 'Nedovrseno'))
            ->andReturn(new GmailDraft(['id' => 'd1', 'message' => ['id' => 'gm-draft', 'threadId' => 'thr-1']]));
        $client->shouldReceive('getMessage')->once()->with('gm-draft', 'full')->andReturn(new GmailMessage([
            'id' => 'gm-draft', 'threadId' => 'thr-1', 'labelIds' => ['DRAFT'], 'internalDate' => (string) now()->getTimestampMs(), 'snippet' => 'Nedovrseno',
            'payload' => ['mimeType' => 'text/plain', 'headers' => [['name' => 'From', 'value' => 'me@example.com'], ['name' => 'Subject', 'value' => 'Re: Ponuda']],
                'body' => ['data' => rtrim(strtr(base64_encode('Nedovrseno'), '+/', '-_'), '=')]],
        ]));

        // Drafts may have no recipient yet.
        $this->postJson("/api/messages/{$original->id}/draft", ['kind' => 'reply', 'to' => [], 'body' => 'Nedovrseno'])
            ->assertStatus(201)
            ->assertJsonPath('data.provider_message_id', 'gm-draft')
            ->assertJsonPath('data.label_ids', ['DRAFT']);

        $this->assertDatabaseHas('messages', ['provider_message_id' => 'gm-draft', 'account_id' => $account->id]);
    }
}
