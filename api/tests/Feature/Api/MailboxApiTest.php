<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncAccountJob;
use App\Jobs\SyncMessageFlagsJob;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Sync\SyncProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxApiTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function account(array $attrs = []): Account
    {
        return $this->makeAccount(array_merge(['display_name' => 'Me', 'scopes' => ['x']], $attrs));
    }

    private function message(Account $account, array $attrs = []): Message
    {
        $n = ++$this->seq;

        return Message::query()->create(array_merge([
            'account_id' => $account->id,
            'provider_message_id' => 'msg'.$n,
            'thread_id' => 'thr'.$n,
            'subject' => 'Subject '.$n,
            'from_name' => 'Sender',
            'from_email' => 'sender@example.com',
            'to_recipients' => [['name' => 'Me', 'email' => 'me@example.com']],
            'cc_recipients' => [],
            'bcc_recipients' => [],
            'reply_to' => [],
            'snippet' => 'snippet '.$n,
            'body_text' => 'body '.$n,
            'body_html' => '<p>body '.$n.'</p>',
            'headers' => [],
            'label_ids' => ['INBOX', 'UNREAD'],
            'is_unread' => true,
            'is_starred' => false,
            'has_attachments' => false,
            'size_estimate' => 10,
            'sent_at' => now()->subMinutes($n),
            'received_at' => now()->subMinutes($n),
        ], $attrs));
    }

    public function test_health(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_accounts_list_hides_tokens_and_counts_messages(): void
    {
        $account = $this->account();
        $this->message($account);
        $this->message($account, ['is_unread' => false, 'label_ids' => ['INBOX']]);
        $this->message($account, ['label_ids' => ['SENT'], 'is_unread' => false]);

        $this->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'me@example.com')
            ->assertJsonPath('data.0.messages_count', 3)
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonMissingPath('data.0.access_token');
    }

    public function test_messages_can_be_listed_oldest_first_across_pages(): void
    {
        $account = $this->account();
        $newest = $this->message($account, ['subject' => 'Newest', 'received_at' => now()->subDay()]);
        $middle = $this->message($account, ['subject' => 'Middle', 'received_at' => now()->subDays(30)]);
        $oldest = $this->message($account, ['subject' => 'Oldest', 'received_at' => now()->subYears(2)]);

        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")
            ->assertOk()->assertJsonPath('data.0.id', $newest->id)->assertJsonPath('data.2.id', $oldest->id);

        $page = $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox&sort=oldest&per_page=2")
            ->assertOk()->assertJsonPath('data.0.id', $oldest->id)->assertJsonPath('data.1.id', $middle->id);

        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox&sort=oldest&per_page=2&cursor=".$page->json('meta.next_cursor'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $newest->id);

        $this->getJson("/api/accounts/{$account->id}/messages?sort=sideways")->assertStatus(422);
    }

    public function test_conversation_rows_and_the_whole_thread(): void
    {
        $account = $this->account();
        $first = $this->message($account, ['thread_id' => 'conv', 'subject' => 'Ispit', 'received_at' => now()->subHours(3), 'is_unread' => true]);
        $first->forceFill(['urgency' => 'high'])->save();
        $mine = $this->message($account, ['thread_id' => 'conv', 'subject' => 'Re: Ispit', 'label_ids' => ['SENT'], 'is_unread' => false, 'received_at' => now()->subHours(2)]);
        $latest = $this->message($account, ['thread_id' => 'conv', 'subject' => 'Re: Ispit', 'received_at' => now()->subHour(), 'is_unread' => false]);
        $other = $this->message($account, ['thread_id' => 'solo', 'subject' => 'Drugo', 'received_at' => now()->subHours(5)]);

        $rows = $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox&conversations=1")->assertOk();
        $this->assertSame([$latest->id, $other->id], array_column($rows->json('data'), 'id'), 'one row per thread, its newest inbox message');
        $rows->assertJsonPath('data.0.thread_count', 3)
            ->assertJsonPath('data.0.thread_unread', 1)
            ->assertJsonPath('data.0.thread_urgency', 'high')
            ->assertJsonPath('data.1.thread_count', 1);

        // The reading view: every message of the conversation, oldest first, sent ones too.
        $thread = $this->getJson("/api/accounts/{$account->id}/messages?thread=conv")->assertOk();
        $this->assertSame([$first->id, $mine->id, $latest->id], array_column($thread->json('data'), 'id'));

        // Without the flag the list is per message, as before.
        $this->assertCount(3, $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox")->json('data'));
    }

    public function test_conversation_list_carries_the_total_and_attachment_chips(): void
    {
        $account = $this->account();
        $withFiles = $this->message($account, ['thread_id' => 'a', 'has_attachments' => true]);
        foreach (['agenda.pdf', 'plan.docx', 'slike.zip', 'budget.xlsx'] as $i => $name) {
            Attachment::query()->create(['message_id' => $withFiles->id, 'filename' => $name, 'mime_type' => 'application/octet-stream', 'size' => 10, 'is_inline' => false]);
        }
        Attachment::query()->create(['message_id' => $withFiles->id, 'filename' => 'logo.png', 'mime_type' => 'image/png', 'size' => 1, 'is_inline' => true]);
        $this->message($account, ['thread_id' => 'b']);
        $this->message($account, ['thread_id' => 'b']);

        $res = $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox&conversations=1&per_page=1")->assertOk()
            ->assertJsonPath('meta.total', 2);
        $row = collect($res->json('data'))->firstWhere('id', $withFiles->id) ?? $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox&conversations=1")->json('data.0');
        $this->assertCount(3, $row['attachment_chips']);
        $this->assertSame(1, $row['attachments_more'], 'four real attachments: three chips and "+1"; the inline logo is not one');
    }

    public function test_summary_explains_the_conversation_with_the_local_model(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true, proofread: false))->save();
        $account = $this->account();
        $this->message($account, ['thread_id' => 'x', 'body_text' => 'Rok za izvještaj je petak.']);
        $last = $this->message($account, ['thread_id' => 'x', 'body_text' => 'Podsjetnik: šaljite do petka.']);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => "- Traži se izvještaj\n- Rok: petak"]]),
        ]);

        $this->postJson("/api/messages/{$last->id}/ai/summary")->assertOk()
            ->assertJsonPath('data.summary', "- Traži se izvještaj\n- Rok: petak")
            ->assertJsonPath('data.messages', 2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat')
            && str_contains($r['messages'][1]['content'], 'Rok za izvještaj je petak.')
            && str_contains($r['messages'][1]['content'], 'Podsjetnik'));

        // Asked again, even from the other message of the thread: stored, no second model call.
        $first = Message::query()->where('thread_id', 'x')->orderBy('id')->first();
        $this->postJson("/api/messages/{$first->id}/ai/summary")->assertOk()
            ->assertJsonPath('data.stored', true)
            ->assertJsonPath('data.summary', "- Traži se izvještaj\n- Rok: petak");
        Http::assertSentCount(2); // one show + one chat

        // A new reply makes the conversation summary stale.
        $this->message($account, ['thread_id' => 'x', 'body_text' => 'Može i u subotu.', 'received_at' => now()]);
        $this->postJson("/api/messages/{$last->id}/ai/summary")->assertOk()
            ->assertJsonPath('data.stored', false)
            ->assertJsonPath('data.messages', 3);
    }

    public function test_one_message_can_be_summarized_with_the_summary_model(): void
    {
        (new AiSettings('ollama', 'http://host.docker.internal:11434', 'gemma4:31b-it-qat', true, summaryModel: 'gemma4:e4b-it-qat', proofread: false))->save();
        $account = $this->account();
        $this->message($account, ['thread_id' => 'x', 'body_text' => 'Prva poruka o sastanku.']);
        $last = $this->message($account, ['thread_id' => 'x', 'body_text' => 'Druga poruka: rok je ponedjeljak.']);
        Http::fake([
            'host.docker.internal:11434/api/show' => Http::response(['capabilities' => ['completion']]),
            'host.docker.internal:11434/api/chat' => Http::response(['message' => ['role' => 'assistant', 'content' => '- Rok: ponedjeljak']]),
        ]);

        $this->postJson("/api/messages/{$last->id}/ai/summary", ['scope' => 'message'])->assertOk()
            ->assertJsonPath('data.messages', 1)
            ->assertJsonPath('data.scope', 'message')
            ->assertJsonPath('data.model', 'gemma4:e4b-it-qat');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/chat')
            && $r['model'] === 'gemma4:e4b-it-qat'
            && str_contains($r['messages'][1]['content'], 'rok je ponedjeljak')
            && ! str_contains($r['messages'][1]['content'], 'Prva poruka'));

        $this->postJson("/api/messages/{$last->id}/ai/summary", ['scope' => 'everything'])->assertStatus(422);

        // The setting is saved through the AI settings endpoint and cleared back to the chat model.
        $this->putJson('/api/ai/settings', ['summary_model' => ''])->assertOk();
        $this->assertNull(AiSettings::load()->summaryModel);
        $this->assertSame('gemma4:31b-it-qat', AiSettings::load()->summarizationModel());
    }

    public function test_accounts_list_reports_sync_progress_and_gmail_total(): void
    {
        $account = $this->account(['sync_status' => Account::STATUS_SYNCING]);
        SyncProgress::start($account, 'range', 8430, 1250);
        SyncProgress::setMailboxTotal($account, 12400);

        $this->getJson('/api/accounts')->assertOk()
            ->assertJsonPath('data.0.sync_progress.total', 8430)
            ->assertJsonPath('data.0.sync_progress.done', 1250)
            ->assertJsonPath('data.0.mailbox_total', 12400);
    }

    public function test_messages_are_filtered_by_folder_and_paginated(): void
    {
        $account = $this->account();
        $this->message($account, ['subject' => 'Inbox one']);
        $this->message($account, ['subject' => 'Sent one', 'label_ids' => ['SENT']]);
        $this->message($account, ['subject' => 'Starred one', 'is_starred' => true, 'label_ids' => ['INBOX', 'STARRED']]);

        $this->getJson("/api/accounts/{$account->id}/messages?folder=inbox&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Inbox one')
            ->assertJsonStructure(['meta' => ['next_cursor']]);

        $this->getJson("/api/accounts/{$account->id}/messages?folder=sent")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Sent one');

        $this->getJson("/api/accounts/{$account->id}/messages?folder=starred")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Starred one');

        $this->getJson("/api/accounts/{$account->id}/messages?folder=all&q=Starred")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_message_detail_includes_body(): void
    {
        $account = $this->account();
        $message = $this->message($account);

        $this->getJson("/api/messages/{$message->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.body_html', '<p>body 1</p>')
            ->assertJsonPath('data.attachments', []);
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Queue::fake();
        $account = $this->account();

        $this->postJson("/api/accounts/{$account->id}/sync", ['mode' => 'backfill', 'limit' => 50])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('account.sync_status', 'queued');

        Queue::assertPushed(SyncAccountJob::class, fn (SyncAccountJob $job) => $job->accountId === $account->id && $job->mode === 'backfill' && $job->limit === 50);
    }

    public function test_sync_is_refused_for_accounts_that_need_reauth(): void
    {
        Queue::fake();
        $account = $this->account(['sync_status' => Account::STATUS_NEEDS_REAUTH]);

        $this->postJson("/api/accounts/{$account->id}/sync")->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_api_key_is_enforced_when_configured(): void
    {
        config(['sovera.api_key' => 'secret']);

        $this->getJson('/api/accounts')->assertStatus(401);
        $this->getJson('/api/accounts', ['X-Api-Key' => 'secret'])->assertOk();
        // health + oauth callback stay public
        $this->getJson('/api/health')->assertOk();
    }

    public function test_removing_an_account_requires_a_choice_about_the_local_mail(): void
    {
        $account = $this->account();
        $this->message($account);

        $this->deleteJson("/api/accounts/{$account->id}")->assertStatus(422);
        $this->assertDatabaseCount('messages', 1);

        $this->deleteJson("/api/accounts/{$account->id}", ['keep_mail' => false])->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_several_conversations_are_marked_read_or_unread_at_once(): void
    {
        Queue::fake();
        $account = $this->account();
        $older = $this->message($account, ['thread_id' => 'conv', 'is_unread' => true]);
        $newest = $this->message($account, ['thread_id' => 'conv', 'is_unread' => true]);
        $solo = $this->message($account, ['thread_id' => 'solo', 'is_unread' => true]);
        $other = $this->message($account, ['thread_id' => 'other', 'is_unread' => true]);

        // Read: every unread message of the picked conversations.
        $this->putJson('/api/messages/read-state', ['message_ids' => [$newest->id, $solo->id], 'unread' => false])->assertOk()
            ->assertJsonPath('data.changed', 3);
        $this->assertFalse($older->fresh()->is_unread);
        $this->assertNotContains('UNREAD', $older->fresh()->label_ids);
        $this->assertFalse($solo->fresh()->is_unread);
        $this->assertTrue($other->fresh()->is_unread, 'not picked, not touched');
        Queue::assertPushed(SyncMessageFlagsJob::class, fn ($j) => count($j->messageIds) === 3 && $j->label === 'UNREAD' && $j->present === false);

        // Unread: only the picked (newest) message of each conversation.
        $this->putJson('/api/messages/read-state', ['message_ids' => [$newest->id], 'unread' => true])->assertOk()
            ->assertJsonPath('data.changed', 1);
        $this->assertTrue($newest->fresh()->is_unread);
        $this->assertFalse($older->fresh()->is_unread);

        $this->putJson('/api/messages/read-state', ['message_ids' => [], 'unread' => true])->assertStatus(422);
    }
}
