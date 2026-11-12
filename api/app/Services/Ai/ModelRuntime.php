<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Which models are in RAM and which of them Sovera uses.
 *
 * Every request Sovera sends to a model leaves a timestamp (kept a day). A loaded model
 * Sovera has not used is another app's: shown as such and never unloaded from here.
 */
final class ModelRuntime
{
    public static function used(string $model): bool
    {
        Cache::put(self::key($model), now()->getTimestamp(), now()->addDay());

        return true;
    }

    /**
     * Run something the user is waiting for (a draft, "Summarize", smart search). Ollama answers
     * one request at a time, so background work pauses (yieldToUser) while this runs.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function interactive(callable $fn): mixed
    {
        Cache::put('ai:interactive-until', now()->getTimestamp() + 600, now()->addMinutes(10));
        try {
            return $fn();
        } finally {
            Cache::forget('ai:interactive-until');
        }
    }

    /** Background work calls this before each model call: it waits while the user waits for the AI. */
    public static function yieldToUser(int $maxSeconds = 600): void
    {
        $until = now()->getTimestamp() + $maxSeconds;
        while ((int) Cache::get('ai:interactive-until', 0) > now()->getTimestamp() && now()->getTimestamp() < $until) {
            sleep(1);
        }
    }

    public static function lastUsed(string $model): ?int
    {
        $at = Cache::get(self::key($model));

        return is_int($at) ? $at : null;
    }

    /**
     * @return array<int, array{name: string, size: int, size_vram: int, expires_at: ?string, ours: bool}>
     */
    public static function loaded(AiSettings $settings): array
    {
        return array_map(
            fn (array $m) => $m + ['ours' => self::lastUsed($m['name']) !== null],
            (new OllamaClient($settings->baseUrl))->running(),
        );
    }

    private static function key(string $model): string
    {
        return 'ollama:used:'.AiSettings::normaliseModel($model);
    }
}
