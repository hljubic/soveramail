<?php

namespace App\Services\Ai\Processing;

use App\Models\Account;
use App\Models\AiSummary;
use App\Models\Category;
use App\Models\Message;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Classify\ThreadClassifier;
use App\Services\Ai\ModelRuntime;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\Proofreader;
use App\Services\Ai\SummaryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Process new mail", conversations step: the chat model reads each new conversation
 * once and answers everything in one JSON reply: its category, the urgency of its
 * unread mail and the "Summarize" lines (whole conversation and newest message), as needed.
 *
 * Only conversations active in the last `sovera.ai.process_days` days: older mail
 * without a category is sorted by "Re-sort" on the Categories page (bulk, own model).
 */
class NewMailProcessor
{
    public function __construct(
        private readonly ThreadClassifier $classifier,
        private readonly SummaryService $summaries,
        private readonly Proofreader $proofreader,
    ) {}

    /**
     * Conversations still waiting, newest activity first: unread without urgency, then
     * without a category, then (unread) without the summaries processing would write.
     *
     * @return string[]
     */
    public function pendingThreads(Account $account, int $limit = 1000): array
    {
        $settings = AiSettings::load();
        $active = ThreadClassifier::isActive($settings);
        $threads = [];

        $add = function (Builder $q) use (&$threads, $limit) {
            foreach ($q->groupBy('thread_id')->orderByRaw('MAX(received_at) DESC')->limit($limit)->pluck('thread_id') as $t) {
                $threads[$t] = true;
            }
        };

        if ($active) {
            $add($this->recent($account)->where('is_unread', true)->whereNull('urgency'));
            $add($this->recent($account)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('thread_categories')
                ->whereColumn('thread_categories.account_id', 'messages.account_id')
                ->whereColumn('thread_categories.thread_id', 'messages.thread_id')));
        }

        if ($settings->summariesInPass && $settings->summarizationModel() !== null) {
            $candidates = $this->recent($account)->where('is_unread', true)
                ->whereRaw("LOWER(COALESCE(from_email, '')) <> ?", [strtolower((string) $account->email)])
                ->groupBy('thread_id')->orderByRaw('MAX(received_at) DESC')->limit(300)->pluck('thread_id');
            foreach ($candidates as $t) {
                if (isset($threads[$t])) {
                    continue;
                }
                $need = $this->summaries->needed($account, $this->classifier->threadMessages($account, $t), $settings);
                if ($need['thread'] || $need['message']) {
                    $threads[$t] = true;
                }
            }
        }

        return array_slice(array_keys($threads), 0, $limit);
    }

    /**
     * Work through pending conversations until the time budget runs out. Conversations in
     * $skip were tried earlier in this pass (an answer without usable summary lines leaves
     * them pending; the next pass tries again).
     *
     * @param  string[]  $skip
     * @return array{done: int, failed: int, remaining: int, tried: string[]}
     */
    public function run(Account $account, int $budgetSeconds = 420, array $skip = [], ?callable $progress = null): array
    {
        $settings = AiSettings::load();
        $categories = Category::query()->ordered()->get();
        $active = ThreadClassifier::isActive($settings, $categories);
        $examples = $active ? $this->classifier->examples($account, $categories) : [];
        $client = new OllamaClient($settings->baseUrl, 8192, thinking: false);
        $deadline = microtime(true) + $budgetSeconds;
        $done = 0;
        $failed = 0;
        $tried = [];
        $skipSet = array_flip($skip);

        foreach ($this->pendingThreads($account, 1000) as $threadId) {
            if (isset($skipSet[$threadId])) {
                continue;
            }
            if (microtime(true) > $deadline) {
                break;
            }
            ModelRuntime::yieldToUser();
            $tried[] = (string) $threadId;
            try {
                $this->processThread($account, (string) $threadId, $categories, $active, $examples, $client, $settings);
                $done++;
            } catch (AiUnavailableException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failed++;
                logger()->warning('Processing a conversation failed', ['thread' => $threadId, 'error' => $e->getMessage()]);
            }
            if ($progress) {
                $progress($done + $failed);
            }
        }

        $left = array_diff($this->pendingThreads($account, 1000), $skip, $tried);

        return ['done' => $done, 'failed' => $failed, 'remaining' => count($left), 'tried' => $tried];
    }

    /**
     * One conversation, normally one model call.
     *
     * @param  Collection<int, Category>  $categories
     */
    public function processThread(Account $account, string $threadId, Collection $categories, bool $active, array $examples, OllamaClient $client, AiSettings $settings): void
    {
        $messages = $this->classifier->threadMessages($account, $threadId);
        if ($messages->isEmpty()) {
            return;
        }

        $needsCategory = $active && ! ThreadCategory::query()->where('account_id', $account->id)->where('thread_id', $threadId)->exists();
        $unread = $messages->filter(fn (Message $m) => $m->is_unread && $m->urgency === null);
        $needsUrgency = $active && $unread->isNotEmpty();
        $need = $this->summaries->needed($account, $messages, $settings);

        $chat = (string) $settings->model;
        $summaryModel = (string) $settings->summarizationModel();
        // Summaries ride along only when the same model writes them; another summary model gets its own call.
        $together = $summaryModel === $chat;
        $threadSummary = $together ? $need['thread'] : null;
        $messageSummary = $together ? $need['message'] : null;

        if ($needsCategory || $needsUrgency || $threadSummary || $messageSummary) {
            $extra = [];
            $properties = [];
            $required = [];
            if ($threadSummary || $messageSummary) {
                $extra[] = '';
                $extra[] = 'SUMMARY for the user (they will read it instead of the e-mails):';
                foreach (SummaryService::instructions($settings) as $line) {
                    $extra[] = $line;
                }
                if ($threadSummary) {
                    $extra[] = $messages->count() > 1
                        ? '"summary": the whole conversation: how it started, what changed, where it stands now.'
                        : '"summary": this e-mail.';
                    $properties['summary'] = ['type' => 'array', 'items' => ['type' => 'string']];
                    $required[] = 'summary';
                }
                if ($messageSummary) {
                    $from = $messageSummary->from_name ?: (string) $messageSummary->from_email;
                    $extra[] = "\"latest\": only the newest unread message (from {$from}), what it adds or asks.";
                    $properties['latest'] = ['type' => 'array', 'items' => ['type' => 'string']];
                    $required[] = 'latest';
                }
                $extra[] = 'Each line is one array item, without a leading dash.';
            }

            $system = ($needsCategory || $needsUrgency)
                ? ThreadClassifier::systemPrompt($account, $categories, $examples, $needsCategory, $needsUrgency, $extra)
                : implode("\n", ['You summarize e-mail for '.$account->email.'.', ...$extra, '', 'Answer with the JSON object only.']);
            $schema = ThreadClassifier::schema($categories, $needsCategory, $needsUrgency);
            $schema['properties'] = (array) $schema['properties'] + $properties;
            $schema['required'] = array_values(array_merge($schema['required'], $required));
            if ($schema['properties'] === []) {
                $schema['properties'] = new \stdClass;
            }

            $answer = $client->chatJson($chat, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => SummaryService::input($messages, $account)],
            ], $schema, timeout: 300) ?? [];

            if ($needsCategory) {
                $this->classifier->storeCategory($account, $threadId, $categories, $answer, $chat);
            }
            if ($needsUrgency) {
                $this->classifier->storeUrgency($unread, $answer, $threadId);
            }
            // Second pass over the summary lines (proofreading), when switched on.
            if ($threadSummary) {
                $this->summaries->store($threadSummary, AiSummary::SCOPE_THREAD, $messages->count(), $chat, $this->proofreader->fixLines((array) ($answer['summary'] ?? []), $settings));
            }
            if ($messageSummary) {
                $this->summaries->store($messageSummary, AiSummary::SCOPE_MESSAGE, 1, $chat, $this->proofreader->fixLines((array) ($answer['latest'] ?? []), $settings));
            }
        }

        // A separate summary model: its own calls, after the conversation is sorted.
        if (! $together) {
            if ($need['thread']) {
                $this->summaries->write($settings, $summaryModel, $need['thread'], AiSummary::SCOPE_THREAD, $messages);
            }
            if ($need['message']) {
                $this->summaries->write($settings, $summaryModel, $need['message'], AiSummary::SCOPE_MESSAGE, collect([$need['message']]));
            }
        }
    }

    /** Mail the processing looks at: what the classifier sorts, from the last days only. */
    private function recent(Account $account): Builder
    {
        return $this->classifier->eligible($account)
            ->where('received_at', '>=', now()->subDays(max(1, (int) config('sovera.ai.process_days', 30))));
    }
}
