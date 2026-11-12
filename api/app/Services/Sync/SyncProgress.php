<?php

namespace App\Services\Sync;

use App\Models\Account;
use Illuminate\Support\Facades\Cache;

/**
 * Live progress of a running sync, for the "1 250 / 8 430" bar on the accounts
 * page. Lives in the cache (not the accounts table): it is only meaningful while
 * a sync runs and is rewritten after every batch.
 *
 * `total` counts what the sync is about to cover (for a range sync: every Gmail
 * message in the range, including the ones already stored), `done` how many of
 * those are now on this computer.
 */
final class SyncProgress
{
    private const TTL_SECONDS = 6 * 3600;

    /** Gmail's own message count is cached a little longer; it changes slowly. */
    private const MAILBOX_TTL_SECONDS = 24 * 3600;

    public static function start(Account $account, string $mode, int $total, int $done = 0): void
    {
        Cache::put(self::key($account->id), [
            'mode' => $mode,
            'total' => max(0, $total),
            'done' => min(max(0, $done), max(0, $total)),
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);
    }

    public static function advance(Account $account, int $count): void
    {
        $progress = self::get($account->id);
        if ($progress === null || $count <= 0) {
            return;
        }
        $progress['done'] = min($progress['total'], $progress['done'] + $count);
        $progress['updated_at'] = now()->toIso8601String();
        Cache::put(self::key($account->id), $progress, self::TTL_SECONDS);
    }

    public static function clear(Account $account): void
    {
        Cache::forget(self::key($account->id));
    }

    /** @return array{mode: string, total: int, done: int, started_at: string, updated_at: string}|null */
    public static function get(int $accountId): ?array
    {
        $value = Cache::get(self::key($accountId));

        return is_array($value) ? $value : null;
    }

    public static function setMailboxTotal(Account $account, int $total): void
    {
        Cache::put('sync-mailbox-total:'.$account->id, $total, self::MAILBOX_TTL_SECONDS);
    }

    public static function mailboxTotal(int $accountId): ?int
    {
        $value = Cache::get('sync-mailbox-total:'.$accountId);

        return is_int($value) ? $value : null;
    }

    private static function key(int $accountId): string
    {
        return 'sync-progress:'.$accountId;
    }
}
