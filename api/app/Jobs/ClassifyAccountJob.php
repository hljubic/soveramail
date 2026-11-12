<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Classify\ThreadClassifier;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sorts an account's conversations into categories and judges the urgency of
 * unread mail with the local model. Works in slices and re-queues itself, so a
 * first run over thousands of threads never blocks syncing for long.
 */
class ClassifyAccountJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $accountId)
    {
        // Own worker (queue-ai): runs alongside syncing instead of waiting behind it.
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /**
     * A second job while one runs is simply dropped: the running one re-queues itself
     * until done. (Released copies used to burn their tries in milliseconds and fail,
     * e.g. after a worker restart left the lock of a killed run behind; the scheduled
     * `sovera:classify` picks the work up again once that lock expires.)
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('account-classify:'.$this->accountId))->expireAfter(900)->dontRelease()];
    }

    public function handle(ThreadClassifier $classifier): void
    {
        $account = Account::query()->find($this->accountId);
        if (! $account || $account->isDisconnected()) {
            return;
        }

        try {
            $result = $classifier->classifyAccount($account, budgetSeconds: 420);
        } catch (AiUnavailableException $e) {
            logger()->info('Classification skipped', ['account' => $account->email, 'reason' => $e->getMessage()]);

            return;
        }

        logger()->info('Classified conversations', ['account' => $account->email] + $result);

        // The model stays until Ollama's idle timer (or the user's "Unload") takes it out.
        if ($result['remaining'] > 0 && ($result['classified'] + $result['urgency']) > 0) {
            self::dispatch($this->accountId)->delay(now()->addSeconds(2));
        }
    }
}
