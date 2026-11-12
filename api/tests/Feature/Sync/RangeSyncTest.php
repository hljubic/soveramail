<?php

namespace Tests\Feature\Sync;

use App\Jobs\SyncAccountJob;
use App\Models\Account;
use App\Models\Message;
use App\Services\Gmail\GmailClient;
use App\Services\Sync\GmailSyncService;
use App\Services\Sync\SyncProgress;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery\MockInterface;
use Tests\TestCase;

class RangeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function account(): Account
    {
        return $this->makeAccount();
    }

    private function inboxMessage(string $id): GmailMessage
    {
        return $this->gmailMessage($id, ['INBOX'], 'S '.$id, 'body '.$id, 'a@b.c', 't'.$id);
    }

    public function test_range_sync_lists_after_date_and_skips_known_messages(): void
    {
        Carbon::setTestNow('2026-09-09 12:00:00');
        $account = $this->account();
        Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'known', 'subject' => 'x', 'to_recipients' => [], 'cc_recipients' => [],
            'bcc_recipients' => [], 'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'received_at' => now(), 'sent_at' => now(),
        ]);

        /** @var MockInterface&GmailClient $client */
        $client = $this->fakeGmail();

        $client->shouldReceive('labels')->andReturn([]);
        $client->shouldReceive('listMessageIds')->once()
            ->withArgs(fn (int $limit, ?string $q) => $q === 'after:2026/03/13' && $limit >= 1000)
            ->andReturn([['id' => 'known', 'threadId' => 't'], ['id' => 'new1', 'threadId' => 't'], ['id' => 'new2', 'threadId' => 't']]);
        $client->shouldReceive('getMessages')->once()
            ->withArgs(fn (array $ids) => $ids === ['new1', 'new2'])
            ->andReturn(['new1' => $this->inboxMessage('new1'), 'new2' => $this->inboxMessage('new2')]);

        $stats = app(GmailSyncService::class)->sync($account, GmailSyncService::MODE_RANGE, null, 180);

        $this->assertSame(['mode' => 'range', 'added' => 2, 'updated' => 0, 'deleted' => 0, 'remaining' => 0], $stats);
        $this->assertSame(3, Message::query()->where('account_id', $account->id)->count());
        $this->assertSame('idle', $account->fresh()->sync_status);
    }

    public function test_range_sync_reports_progress_over_the_whole_range_and_clears_it_when_done(): void
    {
        $account = $this->account();
        Message::query()->create([
            'account_id' => $account->id, 'provider_message_id' => 'known', 'subject' => 'x', 'to_recipients' => [], 'cc_recipients' => [],
            'bcc_recipients' => [], 'reply_to' => [], 'headers' => [], 'label_ids' => ['INBOX'], 'received_at' => now(), 'sent_at' => now(),
        ]);
        config(['sovera.sync.batch_size' => 1, 'sovera.sync.batch_pause_ms' => 0]);

        /** @var MockInterface&GmailClient $client */
        $client = $this->fakeGmail();
        $client->shouldReceive('labels')->andReturn([]);
        $client->shouldReceive('profile')->andReturn(new Profile(['messagesTotal' => 12400]));
        $client->shouldReceive('listMessageIds')->andReturn([['id' => 'known', 'threadId' => 't'], ['id' => 'a', 'threadId' => 't'], ['id' => 'b', 'threadId' => 't']]);

        $seen = [];
        $client->shouldReceive('getMessages')->twice()->andReturnUsing(function (array $ids) use (&$seen, $account) {
            $seen[] = SyncProgress::get($account->id);

            return [$ids[0] => $this->inboxMessage($ids[0])];
        });

        app(GmailSyncService::class)->sync($account, GmailSyncService::MODE_RANGE, null, 30);

        // 3 in range, 1 already here: 1/3 before the first batch, 2/3 before the second.
        $this->assertSame([3, 1], [$seen[0]['total'], $seen[0]['done']]);
        $this->assertSame(2, $seen[1]['done']);
        $this->assertNull(SyncProgress::get($account->id));
        $this->assertSame(12400, SyncProgress::mailboxTotal($account->id));
    }

    public function test_pause_between_batches_grows_while_gmail_rate_limits_and_shrinks_after(): void
    {
        Sleep::fake();
        $account = $this->account();
        config(['sovera.sync.batch_size' => 1, 'sovera.sync.batch_pause_ms' => 600]);

        /** @var MockInterface&GmailClient $client */
        $client = $this->fakeGmail();
        $client->shouldReceive('labels')->andReturn([]);
        $client->shouldReceive('profile')->andReturn(new Profile(['messagesTotal' => 3]));
        $client->shouldReceive('listMessageIds')->andReturn([['id' => 'a', 'threadId' => 't'], ['id' => 'b', 'threadId' => 't'], ['id' => 'c', 'threadId' => 't']]);
        // Batch "a" ran into the limit (counter 0 -> 1), "b" and "c" went through cleanly.
        $client->shouldReceive('rateLimitHits')->andReturn(0, 1, 1, 1, 1, 1);
        $client->shouldReceive('getMessages')->andReturnUsing(fn (array $ids) => [$ids[0] => $this->inboxMessage($ids[0])]);

        app(GmailSyncService::class)->sync($account, GmailSyncService::MODE_RANGE, null, 30);

        Sleep::assertSequence([Sleep::for(1200)->milliseconds(), Sleep::for(960)->milliseconds()]);
    }

    public function test_api_queues_range_job_with_since_days(): void
    {
        Queue::fake();
        $account = $this->account();

        $this->postJson("/api/accounts/{$account->id}/sync", ['mode' => 'range'])->assertStatus(422);

        $this->postJson("/api/accounts/{$account->id}/sync", ['mode' => 'range', 'since_days' => 365])->assertStatus(202);
        Queue::assertPushed(SyncAccountJob::class, fn (SyncAccountJob $j) => $j->mode === 'range' && $j->sinceDays === 365);
    }
}
