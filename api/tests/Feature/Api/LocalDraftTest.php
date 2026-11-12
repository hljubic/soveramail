<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_draft_roundtrip_and_flags(): void
    {
        $account = Account::query()->create([
            'provider' => 'gmail', 'email' => 'me@example.com', 'access_token' => 'at', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
            'scopes' => [Account::SCOPE_GMAIL_MODIFY], 'initial_sync_done' => true, 'history_id' => '1',
        ]);
        $message = Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'gm-1', 'subject' => 'Ponuda', 'from_email' => 'ana@example.com', 'to_recipients' => [], 'cc_recipients' => [],
            'bcc_recipients' => [], 'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'received_at' => now(), 'sent_at' => now(),
        ]);

        $this->getJson("/api/messages/{$message->id}/local-draft")->assertOk()->assertJsonPath('data', null);
        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->assertJsonPath('data.0.has_draft', false);

        $this->putJson("/api/messages/{$message->id}/local-draft", ['kind' => 'reply', 'to' => ['ana@example.com'], 'subject' => 'Re: Ponuda', 'body' => 'Bok Ana, još razmišljam'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Bok Ana, još razmišljam');

        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->assertJsonPath('data.0.has_draft', true);
        $this->getJson("/api/messages/{$message->id}")
            ->assertJsonPath('data.has_draft', true)
            ->assertJsonPath('data.local_draft.kind', 'reply')
            ->assertJsonPath('data.local_draft.to.0', 'ana@example.com');

        // overwrite keeps a single draft per message
        $this->putJson("/api/messages/{$message->id}/local-draft", ['kind' => 'reply', 'to' => ['ana@example.com'], 'body' => 'v2'])->assertOk();
        $this->assertDatabaseCount('local_drafts', 1);

        $this->deleteJson("/api/messages/{$message->id}/local-draft")->assertOk();
        $this->assertDatabaseCount('local_drafts', 0);
    }
}
