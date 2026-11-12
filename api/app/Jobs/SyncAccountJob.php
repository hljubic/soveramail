<?php

namespace App\Jobs;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Services\Ai\Processing\AiPass;
use App\Services\Mail\Providers\MailProvider;
use App\Services\Mail\Providers\MailProviders;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Unique only while waiting in the queue (duplicate clicks collapse into one job);
 * once processing starts the lock is released, so a killed worker can never leave
 * a stale lock that silently drops future syncs. WithoutOverlapping guards the run itself.
 */
class SyncAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Attempts include waiting for another sync of the same account (released, see
     * middleware); real errors are capped by $maxExceptions. With tries = 3 a slice
     * that found the lock of a killed worker burned all three in milliseconds and the
     * fetch stopped.
     */
    public int $tries = 30;

    public int $maxExceptions = 3;

    public int $timeout = 900;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $accountId,
        public readonly string $mode = MailProvider::MODE_INCREMENTAL,
        public readonly ?int $limit = null,
        public readonly ?int $sinceDays = null,
    ) {}

    /**
     * Per account and mode: duplicate clicks / scheduler ticks still collapse, but a
     * waiting incremental can no longer swallow the next slice of a range or
     * backfill (both used to share one lock, so a long fetch silently stopped).
     */
    public function uniqueId(): string
    {
        return $this->accountId.':'.$this->mode;
    }

    public function middleware(): array
    {
        // Another sync of this account is running: try again in a minute (never dropped,
        // a range slice must not get lost; a stale lock expires after 15 minutes).
        return [(new WithoutOverlapping('account-sync:'.$this->accountId))->releaseAfter(60)->expireAfter(900)];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MailProviders $providers): void
    {
        $account = Account::query()->find($this->accountId);
        if (! $account || $account->isDisconnected()) {
            return;
        }

        try {
            $stats = $providers->for($account)->sync($account, $this->mode, $this->limit, $this->sinceDays);
            logger()->info('Account synced', ['account' => $account->email] + $stats);

            // Long range syncs run in slices so one job never exceeds its timeout.
            if (($stats['remaining'] ?? 0) > 0) {
                $account->forceFill(['sync_status' => Account::STATUS_QUEUED])->save();
                self::dispatch($this->accountId, $this->mode, $this->limit, $this->sinceDays)->delay(now()->addSeconds(2));
            }

            // The local AI never starts on its own: new mail only changes the pending counts
            // the "Process new" button shows.
            if (($stats['added'] ?? 0) > 0) {
                AiPass::forget($account->id);
            }
        } catch (ReauthRequiredException $e) {
            // Not retryable: user has to reconnect. Status already set on the account.
            logger()->warning('Account needs re-authorization', ['account' => $account->email, 'error' => $e->getMessage()]);
            $this->delete();
        }
    }

    public function failed(?Throwable $e): void
    {
        $account = Account::query()->find($this->accountId);
        if ($account && $account->sync_status !== Account::STATUS_NEEDS_REAUTH && ! $account->isDisconnected()) {
            $account->markError($e?->getMessage() ?? 'Sync failed.');
        }
    }
}
