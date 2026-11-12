<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\StyleProfile;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Knowledge\KnowledgeBuilder;
use App\Services\Ai\Language\LanguageDetector;
use App\Services\Ai\Processing\AiPass;
use App\Services\Ai\Processing\NewMailProcessor;
use App\Services\Ai\Retrieval\EmbeddingService;
use App\Services\Ai\Style\StyleProfileService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * "Process new mail": every AI job for one account in one pass, grouped by model so each
 * is loaded once (see AiPass::PHASES). Started only by the user's click ("Process new").
 * Works in slices and re-queues itself; never
 * unloads a model (Ollama's idle timer does, or the user's "Unload").
 */
class AiPassJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    private const BUDGET = 420;

    public function __construct(
        public readonly int $accountId,
        public readonly string $phase = 'threads',
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    /** A second pass while one runs is dropped: the running one goes on until done. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ai-pass:'.$this->accountId))->expireAfter(900)->dontRelease()];
    }

    public function handle(NewMailProcessor $processor, StyleProfileService $profiles, KnowledgeBuilder $knowledge, EmbeddingService $embeddings, LanguageDetector $language): void
    {
        $account = Account::query()->find($this->accountId);
        if (! $account || $account->isDisconnected()) {
            return;
        }
        $settings = AiSettings::load();
        $state = AiPass::state($account->id);
        $continuing = ($state['state'] ?? null) === 'running' && AiPass::running($account->id);
        if ($this->phase === 'threads' && ! $continuing) {
            AiPass::put($account->id, [
                'state' => 'running', 'phase' => 'threads', 'done' => 0, 'tried' => [], 'error' => null,
                'total' => count($processor->pendingThreads($account)), 'started_at' => now()->toIso8601String(), 'finished_at' => null,
            ]);
            // First-time reply-language detection, once enough sent mail exists (no-op afterwards).
            try {
                $language->autoSet($account);
            } catch (Throwable $e) {
                report($e);
            }
        }
        AiPass::put($account->id, ['state' => 'running', 'phase' => $this->phase]);

        try {
            $again = match ($this->phase) {
                'threads' => $this->threads($processor, $account),
                'style' => $this->style($profiles, $account),
                'knowledge' => $this->knowledge($knowledge, $account),
                'index' => $this->index($embeddings, $account, $settings),
                default => false,
            };
        } catch (AiUnavailableException $e) {
            AiPass::put($account->id, ['state' => 'error', 'error' => $e->getMessage(), 'finished_at' => now()->toIso8601String()]);
            AiPass::forget($account->id);

            return;
        }

        AiPass::forget($account->id);
        if ($again) {
            self::dispatch($account->id, $this->phase)->delay(now()->addSecond());

            return;
        }
        $next = AiPass::PHASES[array_search($this->phase, AiPass::PHASES, true) + 1] ?? null;
        if ($next !== null) {
            self::dispatch($account->id, $next)->delay(now()->addSecond());

            return;
        }
        AiPass::put($account->id, ['state' => 'done', 'phase' => null, 'tried' => [], 'finished_at' => now()->toIso8601String()]);
        logger()->info('AI pass finished', ['account' => $account->email, 'conversations' => AiPass::state($account->id)['done'] ?? 0]);
    }

    public function failed(?Throwable $e): void
    {
        AiPass::put($this->accountId, ['state' => 'error', 'error' => $e?->getMessage() ?? 'failed', 'finished_at' => now()->toIso8601String()]);
    }

    /** Conversations: category, urgency, summaries. True = more to do. */
    private function threads(NewMailProcessor $processor, Account $account): bool
    {
        $before = AiPass::state($account->id)['done'] ?? 0;
        $result = $processor->run($account, self::BUDGET, AiPass::state($account->id)['tried'] ?? [],
            fn (int $n) => AiPass::put($account->id, ['done' => $before + $n]));
        $tried = array_values(array_unique([...(AiPass::state($account->id)['tried'] ?? []), ...$result['tried']]));
        AiPass::put($account->id, ['tried' => $tried, 'total' => max($before + count($result['tried']) + $result['remaining'], 0)]);

        return $result['remaining'] > 0 && $result['tried'] !== [];
    }

    /** The writing style, when enough new sent mail (or draft corrections) came in. */
    private function style(StyleProfileService $profiles, Account $account): bool
    {
        if (! $profiles->isStale($account)) {
            return false;
        }
        try {
            $profile = $profiles->build($account);
            logger()->info('Style profile built', ['account' => $account->email, 'mails' => $profile->sample_count]);
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            StyleProfile::query()->updateOrCreate(['account_id' => $account->id], ['queued_at' => null, 'last_error' => $e->getMessage()]);
        }

        return false;
    }

    /** What the AI knows about the user: only what is missing. */
    private function knowledge(KnowledgeBuilder $knowledge, Account $account): bool
    {
        if ($knowledge->pendingTasks($account) === []) {
            return false;
        }
        $result = $knowledge->buildPending($account, self::BUDGET);
        logger()->info('Knowledge built', ['account' => $account->email] + $result);

        return $result['remaining'] > 0 && $result['built'] > 0;
    }

    /** The vector index of smart search (embedding model). */
    private function index(EmbeddingService $embeddings, Account $account, AiSettings $settings): bool
    {
        if (! $settings->canEmbed()) {
            return false;
        }
        $result = $embeddings->indexAccount($account, self::BUDGET);

        return $result['remaining'] > 0 && $result['embedded'] > 0;
    }
}
