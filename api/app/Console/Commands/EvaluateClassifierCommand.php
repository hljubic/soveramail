<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Category;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Classify\ThreadClassifier;
use App\Services\Ai\OllamaClient;
use Illuminate\Console\Command;

/**
 * Which local model sorts the user's mail best? Every model answers for the
 * conversations the user sorted BY HAND (the only trustworthy answers) and is
 * scored against them.
 *
 * Privacy: prints numbers only (accuracy, per-category hits, seconds per thread),
 * never a subject, sender or any text of a message; writes nothing.
 */
class EvaluateClassifierCommand extends Command
{
    protected $signature = 'sovera:eval-classifier
        {--models= : Comma-separated Ollama models, e.g. gemma4:e4b-it-qat,gemma4:31b-it-qat}
        {--account= : Account id (default: the first)}
        {--limit=200 : At most this many hand-sorted conversations}';

    protected $description = 'Compare local models on the conversations you sorted by hand (prints numbers only)';

    public function handle(ThreadClassifier $classifier): int
    {
        $settings = AiSettings::load();
        $account = $this->option('account') ? Account::query()->find($this->option('account')) : Account::query()->orderBy('id')->first();
        $models = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('models') ?: $settings->classificationModel())))));
        if (! $account || $models === []) {
            $this->error('No account or no model.');

            return self::FAILURE;
        }

        $categories = Category::query()->ordered()->get();
        $gold = ThreadCategory::query()->where('account_id', $account->id)->where('source', ThreadCategory::SOURCE_USER)
            ->latest('updated_at')->limit((int) $this->option('limit'))->get()
            ->mapWithKeys(fn (ThreadCategory $t) => [$t->thread_id => $categories->firstWhere('id', $t->category_id)?->name])
            ->filter();

        if ($gold->count() < 20) {
            $this->warn("Only {$gold->count()} conversations are sorted by hand. Pick the category of at least 20-50 (above the message) for a meaningful score.");
            if ($gold->isEmpty()) {
                return self::SUCCESS;
            }
        }

        $client = new OllamaClient($settings->baseUrl, 8192, thinking: false);
        $this->info("{$gold->count()} hand-sorted conversations · ".implode(', ', $gold->countBy()->map(fn ($n, $c) => "{$c} {$n}")->all()));

        $rows = [];
        foreach ($models as $model) {
            $hits = 0;
            $perCategory = [];
            $start = microtime(true);
            foreach ($gold as $threadId => $expected) {
                $answer = $classifier->predictCategory($account, (string) $threadId, $categories, $client, $model);
                $ok = $answer === $expected;
                $hits += (int) $ok;
                $perCategory[$expected] = [($perCategory[$expected][0] ?? 0) + (int) $ok, ($perCategory[$expected][1] ?? 0) + 1];
            }
            $seconds = (microtime(true) - $start) / max(1, $gold->count());
            $client->unload($model);
            $rows[] = [
                $model,
                sprintf('%d/%d (%.0f%%)', $hits, $gold->count(), 100 * $hits / max(1, $gold->count())),
                implode('  ', array_map(fn ($c, $v) => "{$c} {$v[0]}/{$v[1]}", array_keys($perCategory), $perCategory)),
                sprintf('%.1f s', $seconds),
            ];
        }

        $this->table(['Model', 'Correct', 'Per category', 'Per thread'], $rows);

        return self::SUCCESS;
    }
}
