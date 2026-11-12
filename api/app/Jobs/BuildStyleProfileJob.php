<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\StyleProfile;
use App\Services\Ai\Style\StyleProfileService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * (Re)builds the writing-style profile of one mailbox from the user's sent mail.
 * Statistics are cheap; the model-written guide runs only when local AI is ready.
 */
class BuildStyleProfileJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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

    public function middleware(): array
    {
        return [(new WithoutOverlapping('style-profile:'.$this->accountId))->expireAfter(900)];
    }

    public function handle(StyleProfileService $profiles): void
    {
        $account = Account::query()->find($this->accountId);
        if (! $account) {
            return;
        }

        try {
            $profile = $profiles->build($account);
            logger()->info('Style profile built', ['account' => $account->email, 'mails' => $profile->sample_count, 'summary' => $profile->summary !== null]);
            // The rest of what the AI knows about the user follows (only what is still missing).
            BuildKnowledgeJob::dispatch($account->id);
        } catch (Throwable $e) {
            report($e);
            StyleProfile::query()->updateOrCreate(['account_id' => $account->id], ['queued_at' => null, 'last_error' => $e->getMessage()]);
        }
    }

    public function failed(?Throwable $e): void
    {
        StyleProfile::query()->updateOrCreate(['account_id' => $this->accountId], ['queued_at' => null, 'last_error' => $e?->getMessage() ?? 'failed']);
    }
}
