<?php

namespace App\Services\Ai\Classify;

use App\Models\Account;
use App\Models\Category;
use App\Models\Message;
use App\Models\ThreadCategory;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\ModelRuntime;
use App\Services\Ai\OllamaClient;
use App\Services\Mail\OwnText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sorts conversations into the user's local categories and judges how urgent
 * unread mail is, with the local model only.
 *
 *  - Category: one per thread, judged from the whole conversation, once. A
 *    category the user picked by hand is never touched.
 *  - Urgency: high / medium / low, once, for messages that are unread when the
 *    classifier gets to them, judged from the whole conversation. Never revisited.
 *
 * The model answers through a JSON schema whose values are enums of the allowed
 * category names / levels, so it can only pick, never invent (a "decision" call).
 */
class ThreadClassifier
{
    public const URGENCY_LEVELS = ['high', 'medium', 'low'];

    /** Characters of conversation the model sees (~2.5k tokens, keeps prompt eval quick). */
    private const MAX_THREAD_CHARS = 7000;

    /** Messages of a long thread that are shown: the first one plus the newest ones. */
    private const MAX_THREAD_MESSAGES = 10;

    /** Hand-picked categories shown to the model as examples of the user's intent. */
    private const MAX_EXAMPLES = 12;

    private const THREADS_PER_QUERY = 25;

    /**
     * @return array{classified: int, urgency: int, remaining: int}
     */
    public function classifyAccount(Account $account, int $budgetSeconds = 420): array
    {
        $settings = AiSettings::load();
        $categories = Category::query()->ordered()->get();
        if (! self::isActive($settings, $categories)) {
            return ['classified' => 0, 'urgency' => 0, 'remaining' => 0];
        }

        $deadline = microtime(true) + $budgetSeconds;
        $client = new OllamaClient($settings->baseUrl, 8192, thinking: false);
        $models = self::models($settings);
        $examples = $this->examples($account, $categories);

        $classified = 0;
        $urgency = 0;
        do {
            // Unread mail first (that is what the user looks at now), then the rest, newest first.
            $batch = $this->pendingUrgencyThreads($account, self::THREADS_PER_QUERY);
            if ($batch === []) {
                $batch = $this->pendingCategoryThreads($account, self::THREADS_PER_QUERY);
            }
            foreach ($batch as $threadId) {
                ModelRuntime::yieldToUser();
                [$c, $u] = $this->classifyThread($account, $threadId, $categories, $examples, $client, $models);
                $classified += $c;
                $urgency += $u;
                if (microtime(true) > $deadline) {
                    break 2;
                }
            }
        } while ($batch !== [] && microtime(true) <= $deadline);

        return ['classified' => $classified, 'urgency' => $urgency, 'remaining' => $this->remaining($account)];
    }

    /**
     * Categories are many (the whole mailbox) and easy: the classification model, which
     * can be a small fast one. Urgency is rare (unread mail only) and harder to judge:
     * the chat model, the strongest one installed. Measured on 106 threads: a 4B model
     * matched the 31B one on 88% of categories at 0.5 s vs 6.5 s, but rated urgency low
     * too often.
     *
     * @return array{category: string, urgency: string}
     */
    public static function models(AiSettings $settings): array
    {
        $category = (string) $settings->classificationModel();

        return ['category' => $category, 'urgency' => (string) ($settings->model ?? $category)];
    }

    /** Needs a model and at least one category of the user's own (not just "Other"). */
    public static function isActive(AiSettings $settings, ?Collection $categories = null): bool
    {
        $categories ??= Category::query()->get();

        return $settings->canClassify() && $categories->contains(fn (Category $c) => ! $c->is_fallback);
    }

    /**
     * One conversation: one call when both answers come from the same model, else one
     * call per model. Returns [category set (0|1), messages given an urgency].
     *
     * @param  Collection<int, Category>  $categories
     * @param  array{category: string, urgency: string}  $models
     * @return array{0: int, 1: int}
     */
    public function classifyThread(Account $account, string $threadId, Collection $categories, array $examples, OllamaClient $client, array $models): array
    {
        $messages = $this->threadMessages($account, $threadId);
        if ($messages->isEmpty()) {
            return [0, 0];
        }

        $existing = ThreadCategory::query()->where('account_id', $account->id)->where('thread_id', $threadId)->first();
        $needsCategory = $existing === null;
        $unread = $messages->filter(fn (Message $m) => $m->is_unread && $m->urgency === null);
        $needsUrgency = $unread->isNotEmpty();
        if (! $needsCategory && ! $needsUrgency) {
            return [0, 0];
        }

        $text = self::conversation($messages, $account);
        $ask = fn (string $model, bool $c, bool $u): array => $client->chatJson($model, [
            ['role' => 'system', 'content' => self::systemPrompt($account, $categories, $examples, $c, $u)],
            ['role' => 'user', 'content' => $text],
        ], self::schema($categories, $c, $u), timeout: 180) ?? [];

        if ($needsCategory && $needsUrgency && $models['category'] === $models['urgency']) {
            $answer = $ask($models['category'], true, true);
        } else {
            $answer = ($needsCategory ? $ask($models['category'], true, false) : [])
                + ($needsUrgency ? $ask($models['urgency'], false, true) : []);
        }
        $setCategory = $needsCategory ? $this->storeCategory($account, $threadId, $categories, $answer, $models['category']) : 0;
        $setUrgency = $needsUrgency ? $this->storeUrgency($unread, $answer, $threadId) : 0;

        return [$setCategory, $setUrgency];
    }

    /**
     * The category the model picked (the fallback for an unusable answer). A row the
     * user created meanwhile wins. Returns 1.
     *
     * @param  Collection<int, Category>  $categories
     */
    public function storeCategory(Account $account, string $threadId, Collection $categories, array $answer, string $model): int
    {
        $category = $this->matchCategory($categories, $answer['category'] ?? null);
        if ($category === null) {
            logger()->warning('Classifier answered no usable category, using the fallback', ['thread' => $threadId]);
            $category = $categories->firstWhere('is_fallback', true);
        }
        ThreadCategory::query()->firstOrCreate(
            ['account_id' => $account->id, 'thread_id' => $threadId],
            ['category_id' => $category->id, 'source' => ThreadCategory::SOURCE_AI, 'model' => $model, 'classified_at' => now()],
        );

        return 1;
    }

    /**
     * The urgency of the unread messages (an unusable answer counts as "low": nothing to
     * flag, and it is not asked again). Returns the number of messages updated.
     *
     * @param  Collection<int, Message>  $unread
     */
    public function storeUrgency(Collection $unread, array $answer, string $threadId): int
    {
        $level = in_array($answer['urgency'] ?? null, self::URGENCY_LEVELS, true) ? $answer['urgency'] : null;
        if ($level === null) {
            logger()->warning('Classifier answered no usable urgency', ['thread' => $threadId]);
            $level = 'low';
        }

        return Message::query()->whereKey($unread->pluck('id'))->whereNull('urgency')
            ->update(['urgency' => $level, 'urgency_at' => now()]);
    }

    /**
     * What $model would answer for a thread, without storing anything (model comparison).
     * No hand-picked examples in the prompt: they are the answers being checked.
     *
     * @param  Collection<int, Category>  $categories
     */
    public function predictCategory(Account $account, string $threadId, Collection $categories, OllamaClient $client, string $model): ?string
    {
        $messages = $this->threadMessages($account, $threadId);
        if ($messages->isEmpty()) {
            return null;
        }
        $answer = $client->chatJson($model, [
            ['role' => 'system', 'content' => self::systemPrompt($account, $categories, [], true, false)],
            ['role' => 'user', 'content' => self::conversation($messages, $account)],
        ], self::schema($categories, true, false), timeout: 180) ?? [];

        return $this->matchCategory($categories, $answer['category'] ?? null)?->name;
    }

    /** @param  Collection<int, Category>  $categories */
    public static function systemPrompt(Account $account, Collection $categories, array $examples, bool $category, bool $urgency, array $extra = []): string
    {
        $me = $account->display_name ? "{$account->display_name} <{$account->email}>" : $account->email;
        $lines = [
            "You sort the e-mail conversations of {$me} for the user's own mail client.",
            'Conversations may be in any language. Judge the conversation as a whole (every message, not only the last one).',
        ];

        if ($category) {
            $lines[] = '';
            $lines[] = 'CATEGORY: pick exactly one, the one this conversation is mainly about.';
            foreach ($categories as $c) {
                $desc = trim((string) $c->description);
                $lines[] = "- \"{$c->name}\"".($desc !== '' ? ": {$desc}" : '');
            }
            $fallback = $categories->firstWhere('is_fallback', true)?->name ?? 'Other';
            // The user prefers a wrong category (easy to spot and move) over mail lost in the fallback.
            $lines[] = "When in doubt between a category and \"{$fallback}\", choose the category: a conversation that is even partly related to a category (its people, partners, project, topic or mailing list) belongs there. Use \"{$fallback}\" only for conversations that have nothing to do with any of the other categories.";
            if ($examples !== []) {
                $lines[] = '';
                $lines[] = 'The user sorted these conversations by hand; follow the same judgement:';
                foreach ($examples as $ex) {
                    $lines[] = "- \"{$ex['subject']}\" (from {$ex['from']}) → {$ex['category']}";
                }
            }
        }

        if ($urgency) {
            $lines[] = '';
            $tz = self::timezone();
            $lines[] = 'URGENCY of the newest unread message(s) for the user. Right now it is '
                .now($tz)->format('l, j F Y, H:i')." ({$tz}); the dates of the messages are in the same time zone. "
                .'Judge deadlines, meetings and "tomorrow / next week" against this moment:';
            $lines[] = '- "high": the user must act or reply within about two days, or a deadline / meeting is near, or someone is waiting on the user right now.';
            $lines[] = '- "medium": the user should act or reply, but it is not pressing.';
            $lines[] = '- "low": information only: newsletters, notifications, mailing lists, automatic mail, CC, nothing for the user to do.';
        }

        foreach ($extra as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Answer with the JSON object only.';

        return implode("\n", $lines);
    }

    /**
     * The conversation as the model reads it: oldest first, each message with
     * sender, recipients, date and its own text (quoted history stripped).
     *
     * @param  Collection<int, Message>  $messages
     */
    public static function conversation(Collection $messages, Account $account): string
    {
        $shown = $messages->count() > self::MAX_THREAD_MESSAGES
            ? collect([$messages->first()])->merge($messages->slice(-(self::MAX_THREAD_MESSAGES - 1)))
            : $messages;
        $skipped = $messages->count() - $shown->count();
        $perMessage = max(400, intdiv(self::MAX_THREAD_CHARS, max(1, $shown->count())));

        $out = 'Subject: '.($messages->first()->subject ?: '(no subject)')."\n";
        foreach ($shown->values() as $i => $m) {
            if ($i === 1 && $skipped > 0) {
                $out .= "\n[… {$skipped} earlier messages left out …]\n";
            }
            $mine = strtolower((string) $m->from_email) === strtolower((string) $account->email);
            $from = $mine ? 'the user' : ($m->from_name ? "{$m->from_name} <{$m->from_email}>" : (string) $m->from_email);
            $to = collect($m->to_recipients ?? [])->pluck('email')->filter()->take(4)->implode(', ');
            $date = ($m->sent_at ?? $m->received_at)?->copy()->setTimezone(self::timezone())->format('D Y-m-d H:i') ?? '';
            $state = $m->is_unread ? ', UNREAD' : '';
            $body = trim(OwnText::of($m->body_text ?? $m->snippet ?? ''));
            if (mb_strlen($body) > $perMessage) {
                $body = mb_substr($body, 0, $perMessage).' […]';
            }
            $out .= "\n--- From {$from}".($to !== '' ? " to {$to}" : '')." · {$date}{$state} ---\n";
            if ($i > 0 && $m->subject && $m->subject !== $messages->first()->subject) {
                $out .= "Subject: {$m->subject}\n";
            }
            $out .= ($body !== '' ? $body : '(no text)')."\n";
        }

        return mb_substr($out, 0, self::MAX_THREAD_CHARS + 2000);
    }

    /** The user's zone for "now" and mail dates in the prompt (the database stores UTC). */
    public static function timezone(): string
    {
        $tz = (string) config('sovera.ai.timezone', 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /** @param  Collection<int, Category>  $categories */
    public static function schema(Collection $categories, bool $category, bool $urgency): array
    {
        $properties = [];
        $required = [];
        if ($category) {
            $properties['category'] = ['type' => 'string', 'enum' => $categories->pluck('name')->values()->all()];
            $required[] = 'category';
        }
        if ($urgency) {
            $properties['urgency'] = ['type' => 'string', 'enum' => self::URGENCY_LEVELS];
            $required[] = 'urgency';
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /** @param  Collection<int, Category>  $categories */
    private function matchCategory(Collection $categories, mixed $name): ?Category
    {
        $name = mb_strtolower(trim((string) $name));

        return $name === '' ? null : $categories->first(fn (Category $c) => mb_strtolower($c->name) === $name);
    }

    /**
     * The user's hand-picked categories, newest first, as examples for the model.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<int, array{subject: string, from: string, category: string}>
     */
    public function examples(Account $account, Collection $categories): array
    {
        $byId = $categories->keyBy('id');

        return ThreadCategory::query()
            ->where('account_id', $account->id)
            ->where('source', ThreadCategory::SOURCE_USER)
            ->latest('updated_at')
            ->limit(self::MAX_EXAMPLES)
            ->get()
            ->map(function (ThreadCategory $tc) use ($account, $byId) {
                $first = Message::query()->where('account_id', $account->id)->where('thread_id', $tc->thread_id)
                    ->orderBy('received_at')->first(['subject', 'from_name', 'from_email']);
                $category = $byId->get($tc->category_id);

                return $first && $category ? [
                    'subject' => mb_substr((string) $first->subject, 0, 120),
                    'from' => $first->from_name ?: (string) $first->from_email,
                    'category' => $category->name,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Mail that gets a category: visible, part of a thread, not spam / trash / drafts,
     * and (when the user limited it) received in the last N days. A thread counts once
     * any of its messages falls in that window; the model still reads all of it.
     */
    public function eligible(Account $account): Builder
    {
        $query = Message::query()
            ->where('account_id', $account->id)
            ->visible()
            ->whereNotNull('thread_id')
            ->whereJsonDoesntContain('label_ids', 'SPAM')
            ->whereJsonDoesntContain('label_ids', 'TRASH')
            ->whereJsonDoesntContain('label_ids', 'DRAFT');

        if ($days = AiSettings::load()->classifySinceDays) {
            $query->where('received_at', '>=', now()->subDays($days));
        }

        return $query;
    }

    /** Every visible message of a thread, oldest first (the window never cuts a conversation). */
    public function threadMessages(Account $account, string $threadId): Collection
    {
        return Message::query()
            ->where('account_id', $account->id)
            ->where('thread_id', $threadId)
            ->visible()
            ->whereJsonDoesntContain('label_ids', 'SPAM')
            ->whereJsonDoesntContain('label_ids', 'TRASH')
            ->whereJsonDoesntContain('label_ids', 'DRAFT')
            ->orderBy('received_at')->orderBy('id')
            ->get();
    }

    /** @return string[] threads without a category, newest activity first */
    public function pendingCategoryThreads(Account $account, int $limit): array
    {
        return $this->eligible($account)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('thread_categories')
                ->whereColumn('thread_categories.account_id', 'messages.account_id')
                ->whereColumn('thread_categories.thread_id', 'messages.thread_id'))
            ->groupBy('thread_id')
            ->orderByRaw('MAX(received_at) DESC')
            ->limit($limit)
            ->pluck('thread_id')
            ->all();
    }

    /** @return string[] threads with unread mail whose urgency was never judged, newest first */
    public function pendingUrgencyThreads(Account $account, int $limit): array
    {
        return $this->eligible($account)
            ->where('is_unread', true)
            ->whereNull('urgency')
            ->groupBy('thread_id')
            ->orderByRaw('MAX(received_at) DESC')
            ->limit($limit)
            ->pluck('thread_id')
            ->all();
    }

    /** Threads still waiting for a category or an urgency. */
    public function remaining(Account $account): int
    {
        $categories = (clone $this->eligible($account))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('thread_categories')
                ->whereColumn('thread_categories.account_id', 'messages.account_id')
                ->whereColumn('thread_categories.thread_id', 'messages.thread_id'))
            ->distinct()->count('thread_id');
        $urgency = (clone $this->eligible($account))->where('is_unread', true)->whereNull('urgency')->distinct()->count('thread_id');

        return $categories + $urgency;
    }

    /**
     * For the progress bar: threads with a category out of all threads.
     *
     * @return array{total: int, done: int, unread_pending: int}
     */
    public function progress(Account $account): array
    {
        $total = (clone $this->eligible($account))->distinct()->count('thread_id');
        $pending = (clone $this->eligible($account))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('thread_categories')
                ->whereColumn('thread_categories.account_id', 'messages.account_id')
                ->whereColumn('thread_categories.thread_id', 'messages.thread_id'))
            ->distinct()->count('thread_id');
        $unread = (clone $this->eligible($account))->where('is_unread', true)->whereNull('urgency')->count();

        return ['total' => $total, 'done' => max(0, $total - $pending), 'unread_pending' => $unread];
    }

    /** @throws AiUnavailableException when the model server is not set up */
    public static function assertReady(): void
    {
        if (! self::isActive(AiSettings::load())) {
            throw new AiUnavailableException('Pick a local model (Configure AI) and define at least one category first.');
        }
    }
}
