<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Knowledge\KnowledgeBuilder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Builds what the local AI knows about the user (about, per-category guides and
 * recurring answers, contact cards) in slices on the AI worker; continues after
 * a restart because every finished item is stored.
 */
class BuildKnowledgeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $accountId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /** A duplicate is dropped, not released (see ClassifyAccountJob). */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('account-knowledge:'.$this->accountId))->expireAfter(900)->dontRelease()];
    }

    public function handle(KnowledgeBuilder $builder): void
    {
        $account = Account::query()->find($this->accountId);
        if (! $account || $account->isDisconnected()) {
            return;
        }

        try {
            $result = $builder->buildPending($account, budgetSeconds: 420);
        } catch (AiUnavailableException $e) {
            logger()->info('Knowledge skipped', ['account' => $account->email, 'reason' => $e->getMessage()]);

            return;
        }

        // Counts only, never content.
        logger()->info('Knowledge built', ['account' => $account->email] + $result);

        if ($result['remaining'] > 0 && $result['built'] > 0) {
            self::dispatch($this->accountId)->delay(now()->addSeconds(2));
        }
    }
}
