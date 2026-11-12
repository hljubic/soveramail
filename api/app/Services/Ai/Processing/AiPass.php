<?php

namespace App\Services\Ai\Processing;

use App\Models\Account;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Knowledge\KnowledgeBuilder;
use App\Services\Ai\Retrieval\EmbeddingService;
use App\Services\Ai\Style\StyleProfileService;
use Illuminate\Support\Facades\Cache;

/**
 * State of "Process new mail" per account (shown by the UI) and what is still waiting.
 *
 * Phases, each model loaded once: conversations (chat model: category, urgency,
 * summaries) → style (if enough new sent mail) → knowledge (what is missing) →
 * index (embedding model).
 */
final class AiPass
{
    public const PHASES = ['threads', 'style', 'knowledge', 'index'];

    /** @return array{state: string, phase: ?string, total: int, done: int, started_at: ?string, finished_at: ?string, error: ?string, tried: string[]}|null */
    public static function state(int $accountId): ?array
    {
        $s = Cache::get(self::key($accountId));

        return is_array($s) ? $s : null;
    }

    public static function put(int $accountId, array $changes): array
    {
        $state = array_merge(self::state($accountId) ?? [
            'state' => 'idle', 'phase' => null, 'total' => 0, 'done' => 0,
            'started_at' => null, 'finished_at' => null, 'error' => null, 'tried' => [],
        ], $changes, ['updated_at' => now()->getTimestamp()]);
        Cache::put(self::key($accountId), $state, now()->addDay());

        return $state;
    }

    /** Queued or running, and heard from recently (a killed worker does not block the next pass forever). */
    public static function running(int $accountId): bool
    {
        $s = self::state($accountId);

        return in_array($s['state'] ?? null, ['queued', 'running'], true)
            && now()->getTimestamp() - (int) ($s['updated_at'] ?? 0) < 1200;
    }

    /**
     * What is waiting (cheap counts, no AI): conversations, messages to index, a stale
     * writing style, missing knowledge items.
     *
     * @return array{threads: int, index: int, style: bool, knowledge: int}
     */
    public static function pending(Account $account): array
    {
        return Cache::remember('ai-pass-pending:'.$account->id, now()->addSeconds(30), function () use ($account) {
            $settings = AiSettings::load();
            if (! $settings->isReady()) {
                return ['threads' => 0, 'index' => 0, 'style' => false, 'knowledge' => 0];
            }
            $index = 0;
            if ($settings->canEmbed()) {
                $stats = app(EmbeddingService::class)->stats($account);
                $index = max(0, $stats['total'] - $stats['indexed']);
            }

            return [
                'threads' => count(app(NewMailProcessor::class)->pendingThreads($account)),
                'index' => $index,
                'style' => app(StyleProfileService::class)->isStale($account),
                'knowledge' => count(app(KnowledgeBuilder::class)->pendingTasks($account)),
            ];
        });
    }

    public static function forget(int $accountId): void
    {
        Cache::forget('ai-pass-pending:'.$accountId);
    }

    /** @param  array{threads: int, index: int, style: bool, knowledge: int}  $p */
    public static function hasWork(array $p): bool
    {
        return $p['threads'] > 0 || $p['index'] > 0 || $p['style'] || $p['knowledge'] > 0;
    }

    private static function key(int $accountId): string
    {
        return 'ai-pass:'.$accountId;
    }
}
