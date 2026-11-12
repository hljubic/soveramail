<?php

namespace App\Services\Sync;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Models\Label;
use App\Models\Message;
use App\Services\Gmail\GmailClient;
use App\Services\Gmail\GmailClientResolver;
use App\Services\Gmail\GmailMessageParser;
use App\Services\Mail\Providers\MailProvider;
use Carbon\Carbon;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail\Message as GmailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Pulls mail from Gmail into the local database.
 *
 *  - full:        newest N messages (first run, or when history is gone)
 *  - incremental: users.history.list since the stored historyId
 *  - backfill:    N older messages than the oldest one we have
 */
class GmailSyncService
{
    public const MODE_FULL = MailProvider::MODE_FULL;

    public const MODE_INCREMENTAL = MailProvider::MODE_INCREMENTAL;

    public const MODE_BACKFILL = MailProvider::MODE_BACKFILL;

    public const MODE_RANGE = MailProvider::MODE_RANGE;

    /** Hard cap on ids listed for a range sync (Gmail lists 500 per page). */
    private const RANGE_MAX_IDS = 100000;

    /**
     * Seconds a single range run may spend (listing + fetching) before handing back
     * to the queue. Well under SyncAccountJob::$timeout (900 s): one batch can still
     * spend up to ~170 s in rate-limit pauses after the budget runs out.
     */
    private const RANGE_BUDGET_SECONDS = 420;

    /** Upper bound for the adaptive pause between batches while Gmail keeps rate limiting. */
    private const MAX_PAUSE_MS = 5000;

    public function __construct(
        private readonly GmailMessageParser $parser,
        private readonly MessageStore $messages,
        private readonly GmailClientResolver $gmail,
    ) {}

    /**
     * @return array{mode: string, added: int, updated: int, deleted: int, remaining: int}
     */
    public function sync(Account $account, string $mode = self::MODE_INCREMENTAL, ?int $limit = null, ?int $sinceDays = null): array
    {
        $limit = $this->normaliseLimit($limit);
        $account->markSyncing();

        try {
            $gmail = $this->gmail->for($account);

            // A range sync runs in slices: keep the bar of the previous slice until
            // this one has listed its ids. Every other mode starts from scratch.
            if ($mode !== self::MODE_RANGE) {
                SyncProgress::clear($account);
            }

            $this->syncLabels($account, $gmail);
            if ($mode !== self::MODE_INCREMENTAL) {
                $this->rememberMailboxTotal($account, $gmail);
            }

            if ($mode === self::MODE_RANGE) {
                $stats = $this->range($account, $gmail, max(0, (int) $sinceDays));
            } elseif ($mode === self::MODE_BACKFILL) {
                $stats = $this->backfill($account, $gmail, $limit);
            } elseif ($mode === self::MODE_FULL || ! $account->initial_sync_done || ! $account->history_id) {
                $stats = $this->fullSync($account, $gmail, $limit);
            } else {
                try {
                    $stats = $this->incrementalSync($account, $gmail);
                } catch (GoogleServiceException $e) {
                    // 404 = historyId too old; Gmail asks for a full resync.
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                    logger()->info('Gmail history expired, running full sync', ['account' => $account->email]);
                    $stats = $this->fullSync($account, $gmail, $limit);
                }
            }

            if (($stats['remaining'] ?? 0) === 0) {
                SyncProgress::clear($account);
            }
            $account->markIdle();

            return $stats;
        } catch (ReauthRequiredException $e) {
            $account->markNeedsReauth($e->getMessage() ?: 'Google authorization expired. Please reconnect this account.');
            throw $e;
        } catch (GoogleServiceException $e) {
            $account->markError(GmailClient::friendlyMessage($e));
            throw $e;
        } catch (Throwable $e) {
            $account->markError($e->getMessage());
            throw $e;
        }
    }

    /** Gmail's own message count, for "4 132 of ~12 400" on the accounts page. Never fails a sync. */
    private function rememberMailboxTotal(Account $account, GmailClient $gmail): void
    {
        try {
            $total = $gmail->profile()->getMessagesTotal();
            if ($total !== null) {
                SyncProgress::setMailboxTotal($account, (int) $total);
            }
        } catch (ReauthRequiredException $e) {
            throw $e;
        } catch (Throwable) {
            // purely informational
        }
    }

    private function syncLabels(Account $account, GmailClient $gmail): void
    {
        $seen = [];
        foreach ($gmail->labels() as $label) {
            $id = (string) $label->getId();
            $seen[] = $id;
            $color = $label->getColor();

            Label::query()->updateOrCreate(
                ['account_id' => $account->id, 'provider_label_id' => $id],
                [
                    'name' => (string) $label->getName(),
                    'type' => $label->getType() === 'system' ? 'system' : 'user',
                    'color_bg' => $color?->getBackgroundColor(),
                    'color_fg' => $color?->getTextColor(),
                    'visible' => $label->getLabelListVisibility() !== 'labelHide',
                ],
            );
        }

        if ($seen !== []) {
            Label::query()->where('account_id', $account->id)->whereNotIn('provider_label_id', $seen)->delete();
        }
    }

    /** @return array{mode: string, added: int, updated: int, deleted: int, remaining: int} */
    private function fullSync(Account $account, GmailClient $gmail, int $limit): array
    {
        // Take the history cursor *before* listing so nothing slips between the two calls.
        $profile = $gmail->profile();
        $historyId = (string) $profile->getHistoryId();

        $ids = array_column($gmail->listMessageIds($limit), 'id');
        SyncProgress::start($account, self::MODE_FULL, count($ids));
        [$added, $updated] = $this->fetchAndStore($account, $gmail, $ids);

        $account->forceFill([
            'history_id' => $historyId,
            'initial_sync_done' => true,
        ])->save();

        return ['mode' => self::MODE_FULL, 'added' => $added, 'updated' => $updated, 'deleted' => 0, 'remaining' => 0];
    }

    /** @return array{mode: string, added: int, updated: int, deleted: int, remaining: int} */
    private function incrementalSync(Account $account, GmailClient $gmail): array
    {
        $added = [];
        $deleted = [];
        $labelChanged = [];
        $latestHistoryId = $account->history_id;
        $pageToken = null;

        do {
            $response = $gmail->listHistory((string) $account->history_id, $pageToken);
            if ($response->getHistoryId()) {
                $latestHistoryId = (string) $response->getHistoryId();
            }

            foreach ($response->getHistory() ?? [] as $history) {
                foreach ($history->getMessagesAdded() ?? [] as $item) {
                    $added[$item->getMessage()->getId()] = true;
                }
                foreach ($history->getMessagesDeleted() ?? [] as $item) {
                    $id = $item->getMessage()->getId();
                    $deleted[$id] = true;
                    unset($added[$id]);
                }
                foreach ($history->getLabelsAdded() ?? [] as $item) {
                    $labelChanged[$item->getMessage()->getId()] = true;
                }
                foreach ($history->getLabelsRemoved() ?? [] as $item) {
                    $labelChanged[$item->getMessage()->getId()] = true;
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        // Gmail says these are gone for good. The local copy is kept (this app never
        // deletes mail); it is only hidden from the folders. `deleted` in the stats
        // counts how many were hidden.
        $deletedCount = $this->messages->hide($account, array_keys($deleted));

        [$addedCount, $updatedCount] = $this->fetchAndStore($account, $gmail, array_keys($added));

        // Label-only changes: refresh label sets with the cheap `minimal` format.
        $labelOnly = array_diff(array_keys($labelChanged), array_keys($added), array_keys($deleted));
        if ($labelOnly !== []) {
            $known = Message::query()
                ->where('account_id', $account->id)
                ->whereIn('provider_message_id', $labelOnly)
                ->pluck('provider_message_id')
                ->all();

            foreach ($gmail->getMessages($known, 'minimal') as $id => $msg) {
                $labels = array_values(array_map('strval', $msg->getLabelIds() ?? []));
                Message::query()
                    ->where('account_id', $account->id)
                    ->where('provider_message_id', $id)
                    ->update([
                        'label_ids' => json_encode($labels),
                        'is_unread' => in_array('UNREAD', $labels, true),
                        'is_starred' => in_array('STARRED', $labels, true),
                        'updated_at' => now(),
                    ]);
                $updatedCount++;
            }
        }

        if ($latestHistoryId && $latestHistoryId !== $account->history_id) {
            $account->forceFill(['history_id' => $latestHistoryId])->save();
        }

        return ['mode' => self::MODE_INCREMENTAL, 'added' => $addedCount, 'updated' => $updatedCount, 'deleted' => $deletedCount, 'remaining' => 0];
    }

    /**
     * Everything newer than N days ago that we do not have yet (Gmail `after:`).
     * Works in time slices: the job re-queues itself while `remaining` > 0.
     *
     * @return array{mode: string, added: int, updated: int, deleted: int, remaining: int}
     */
    private function range(Account $account, GmailClient $gmail, int $sinceDays): array
    {
        // The budget includes listing: re-listing a big mailbox every slice takes a while too.
        $deadline = microtime(true) + self::RANGE_BUDGET_SECONDS;

        // 0 = everything (no date filter)
        $query = $sinceDays > 0 ? 'after:'.now()->subDays($sinceDays)->format('Y/m/d') : null;

        $known = $this->knownProviderIds($account);

        $listed = $gmail->listMessageIds(self::RANGE_MAX_IDS, $query);
        $candidates = [];
        foreach ($listed as $item) {
            if (! $known->has($item['id'])) {
                $candidates[] = $item['id'];
            }
        }

        // Progress over the whole range: what is already here counts as done.
        SyncProgress::start($account, self::MODE_RANGE, count($listed), count($listed) - count($candidates));

        [$added, $updated, $remaining] = $this->fetchAndStore($account, $gmail, $candidates, $deadline);

        return ['mode' => self::MODE_RANGE, 'added' => $added, 'updated' => $updated, 'deleted' => 0, 'remaining' => $remaining];
    }

    /** Gmail ids we already store, as a set (id => index) for O(1) lookups. */
    private function knownProviderIds(Account $account): Collection
    {
        return Message::query()->where('account_id', $account->id)->pluck('provider_message_id')->flip();
    }

    /** @return array{mode: string, added: int, updated: int, deleted: int, remaining: int} */
    private function backfill(Account $account, GmailClient $gmail, int $limit): array
    {
        $oldest = Message::query()
            ->where('account_id', $account->id)
            ->orderBy('received_at')
            ->value('received_at');

        // Gmail's `before:` has day granularity; +1 day so the boundary day is included
        // (already-known ids are skipped below).
        $query = $oldest ? 'before:'.Carbon::parse($oldest)->addDay()->format('Y/m/d') : null;

        $known = $this->knownProviderIds($account);

        // $limit is already capped at sovera.sync.max_limit by normaliseLimit().
        $candidates = [];
        foreach ($gmail->listMessageIds($limit + $known->count(), $query) as $item) {
            if (! $known->has($item['id'])) {
                $candidates[] = $item['id'];
                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        SyncProgress::start($account, self::MODE_BACKFILL, count($candidates));
        [$added, $updated] = $this->fetchAndStore($account, $gmail, $candidates);

        return ['mode' => self::MODE_BACKFILL, 'added' => $added, 'updated' => $updated, 'deleted' => 0, 'remaining' => 0];
    }

    /**
     * @param  string[]  $ids
     * @param  float|null  $deadline  microtime() after which no new batch is started
     * @return array{0: int, 1: int, 2: int} [added, updated, remaining]
     */
    private function fetchAndStore(Account $account, GmailClient $gmail, array $ids, ?float $deadline = null): array
    {
        if ($ids === []) {
            return [0, 0, 0];
        }

        $added = 0;
        $updated = 0;
        $done = 0;

        // Gmail allows 15 000 quota units per user per minute and messages.get costs 5,
        // so a short pause between batches keeps a big backfill under the limit. The
        // pause adapts: it doubles after a batch that hit the limit and shrinks back
        // after clean ones, so a busy mailbox settles at the speed Gmail accepts
        // instead of sleeping 5-30 s on almost every batch.
        $basePauseMs = max(0, (int) config('sovera.sync.batch_pause_ms', 600));
        $pauseMs = $basePauseMs;

        foreach (array_chunk($ids, (int) config('sovera.sync.batch_size', 25)) as $i => $chunk) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            if ($i > 0 && $pauseMs > 0) {
                Sleep::for($pauseMs)->milliseconds();
            }
            $hitsBefore = $gmail->rateLimitHits();
            $failed = 0;
            foreach ($gmail->getMessages($chunk, 'full') as $gmailMessage) {
                try {
                    $isNew = $this->store($account, $gmailMessage);
                    $isNew ? $added++ : $updated++;
                } catch (Throwable $e) {
                    // One unparseable / unstorable message is skipped so the cursor can
                    // move on; a whole failing batch is a real problem and stops the run.
                    if (++$failed >= max(3, count($chunk))) {
                        throw $e;
                    }
                    logger()->warning('Skipped a message that could not be stored', ['account' => $account->id, 'id' => $gmailMessage->getId(), 'error' => $e->getMessage()]);
                }
            }
            $done += count($chunk);
            SyncProgress::advance($account, count($chunk));

            $pauseMs = $gmail->rateLimitHits() > $hitsBefore
                ? min(self::MAX_PAUSE_MS, max($pauseMs, 250) * 2)
                : max($basePauseMs, (int) ($pauseMs * 0.8));
        }

        return [$added, $updated, max(0, count($ids) - $done)];
    }

    /**
     * Upsert one Gmail message through the shared local store. Returns true when inserted.
     */
    public function store(Account $account, GmailMessage $gmailMessage): bool
    {
        return $this->messages->store($account, $this->parser->parse($gmailMessage));
    }

    private function normaliseLimit(?int $limit): int
    {
        $default = (int) config('sovera.sync.initial_limit', 100);
        $max = (int) config('sovera.sync.max_limit', 2000);

        return max(1, min($limit ?? $default, $max));
    }
}
