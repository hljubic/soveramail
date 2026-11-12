<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Retrieval\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Builds the local vector index for one account. Re-queues itself while
 * messages remain, so big mailboxes are processed in slices.
 */
class EmbedAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $accountId)
    {
        // Own worker (queue-ai): runs alongside syncing instead of waiting behind it.
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /** A duplicate is dropped, not released (released copies fail within milliseconds); see ClassifyAccountJob. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('account-embed:'.$this->accountId))->expireAfter(600)->dontRelease()];
    }

    public function handle(EmbeddingService $embeddings): void
    {
        $account = Account::query()->find($this->accountId);
        if (! $account) {
            return;
        }

        try {
            $result = $embeddings->indexAccount($account, budgetSeconds: 480);
        } catch (AiUnavailableException $e) {
            logger()->info('Embedding skipped', ['account' => $account->email, 'reason' => $e->getMessage()]);

            return;
        }

        logger()->info('Embedded messages', ['account' => $account->email] + $result);

        if ($result['remaining'] > 0 && $result['embedded'] > 0) {
            self::dispatch($this->accountId)->delay(now()->addSeconds(2));

            return;
        }

    }
}
