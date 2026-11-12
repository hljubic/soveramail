<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\AiSummary;
use App\Models\Message;
use App\Services\Ai\Classify\ThreadClassifier;
use App\Services\Ai\Language\Languages;
use App\Services\Mail\OwnText;
use Illuminate\Support\Collection;

/**
 * "Summarize": short lines on what a conversation (or one message of it) says, written by the
 * local model chosen for summaries (the chat model unless the user picked another).
 * As many lines as there are facts worth knowing, no fixed number.
 *
 * Results are stored (ai_summaries). "Process new mail" (NewMailProcessor) writes them for
 * unread mail in the same model call as the category and urgency; anything else is
 * written on the first click and kept.
 */
class SummaryService
{
    /**
     * @param  'thread'|'message'  $scope
     * @return array{summary: string, model: string, messages: int, scope: string, stored: bool}
     */
    public function summarize(Message $message, string $scope = 'thread'): array
    {
        $settings = AiSettings::load();
        $model = $this->model($settings);

        [$anchor, $messages] = $this->target($message, $scope);
        $row = $this->stored($anchor, $scope, $messages->count(), $model);
        $stored = $row !== null;
        $row ??= ModelRuntime::interactive(fn () => $this->write($settings, $model, $anchor, $scope, $messages));

        return ['summary' => $row->summary, 'model' => $model, 'messages' => $messages->count(), 'scope' => $scope, 'stored' => $stored];
    }

    /**
     * What the processing pass should summarize in a conversation: the whole thread
     * (stored under its newest message) and/or its newest unread message (only when the
     * thread has more than one). Nothing when summaries are off for processing, for
     * newsletters / mailing lists / no-reply mail (when skipped) or below the word limit.
     *
     * @param  Collection<int, Message>  $messages  the thread, oldest first
     * @return array{thread: ?Message, message: ?Message}
     */
    public function needed(Account $account, Collection $messages, AiSettings $settings): array
    {
        $none = ['thread' => null, 'message' => null];
        $model = $settings->summarizationModel();
        if (! $settings->summariesInPass || $model === null || $messages->isEmpty()) {
            return $none;
        }
        $unread = $messages->filter(fn (Message $m) => $m->is_unread && ! $this->isMine($account, $m));
        if ($unread->isEmpty()) {
            return $none;
        }
        if ($settings->summarySkipBulk && $messages->every(fn (Message $m) => $this->isMine($account, $m) || self::isBulk($m))) {
            return $none;
        }

        $min = $settings->summaryMinWords;
        $anchor = $messages->last();
        $thread = self::words($messages) >= $min && ! $this->stored($anchor, AiSummary::SCOPE_THREAD, $messages->count(), $model)
            ? $anchor : null;

        $newest = $unread->last();
        $message = $messages->count() > 1
            && ! ($settings->summarySkipBulk && self::isBulk($newest))
            && self::words(collect([$newest])) >= $min
            && ! $this->stored($newest, AiSummary::SCOPE_MESSAGE, 1, $model)
            ? $newest : null;

        return ['thread' => $thread, 'message' => $message];
    }

    /**
     * Instructions for the summary lines, shared by the "Summarize" button and the combined
     * processing call.
     *
     * @return string[]
     */
    public static function instructions(AiSettings $settings): array
    {
        // The user's reply language when set, else the language of the mail itself.
        $lang = $settings->replyLanguage !== null ? Languages::name($settings->replyLanguage) : 'the language the e-mails are written in';

        return [
            "Write the summary in {$lang}: one short line per important fact.",
            'Write as many lines as there are facts worth knowing: a short note may need 1 or 2, a long or dense one 8 to 12. Never pad and never leave out something that matters.',
            'Worth a line: what it is about; what is asked of the reader and by when; dates, deadlines, places, amounts, numbers, names, decisions, links or attachments that matter.',
            'Only what the e-mails say, never guess. No heading, no introduction, no closing remark.',
        ];
    }

    /** The conversation as the model reads it, plus the names of real attachments. */
    public static function input(Collection $messages, Account $account): string
    {
        $input = ThreadClassifier::conversation($messages, $account);
        $files = $messages->flatMap(fn (Message $m) => $m->attachments()->where('is_inline', false)->pluck('filename'))
            ->filter()->unique()->take(20)->implode(', ');

        return $files !== '' ? $input."\nAttachments: {$files}\n" : $input;
    }

    /**
     * Keep the summary lines the model wrote.
     *
     * @param  array<int, mixed>  $lines
     */
    public function store(Message $anchor, string $scope, int $messages, string $model, array $lines): ?AiSummary
    {
        $lines = array_values(array_filter(array_map(
            fn ($l) => trim((string) preg_replace(['/^\s*[-*•]\s*/u', '/\*\*(.+?)\*\*/u'], ['', '$1'], (string) $l)),
            $lines,
        ), fn ($l) => $l !== ''));
        if ($lines === []) {
            return null;
        }

        return AiSummary::query()->updateOrCreate(
            ['message_id' => $anchor->id, 'scope' => $scope],
            ['messages' => $messages, 'model' => $model, 'summary' => '- '.implode("\n- ", $lines)],
        );
    }

    /** Words of the messages' own text (quoted history and signatures do not count). */
    public static function words(Collection $messages): int
    {
        return (int) $messages->sum(fn (Message $m) => count(preg_split('/\s+/u', trim(OwnText::of($m->body_text ?? $m->snippet ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** Newsletters, mailing lists, automatic mail: recognised from headers and sender, no AI. */
    public static function isBulk(Message $message): bool
    {
        $headers = array_change_key_case((array) ($message->headers ?? []), CASE_LOWER);
        if (isset($headers['list-unsubscribe']) || isset($headers['list-id'])) {
            return true;
        }
        if (in_array(strtolower(trim((string) ($headers['precedence'] ?? ''))), ['bulk', 'list', 'junk'], true)) {
            return true;
        }
        $auto = strtolower(trim((string) ($headers['auto-submitted'] ?? '')));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }

        return (bool) preg_match('/^(no-?reply|do-?not-?reply|newsletter|mailer-daemon|notifications?)[+.\-_@]/i', (string) $message->from_email);
    }

    private function isMine(Account $account, Message $m): bool
    {
        return strtolower((string) $m->from_email) === strtolower((string) $account->email);
    }

    private function model(AiSettings $settings): string
    {
        $model = $settings->summarizationModel();
        if (! $settings->enabled || $model === null || $settings->baseUrl === '') {
            throw new AiUnavailableException('Local AI is not configured. Open "Configure AI" and pick an Ollama model.');
        }

        return $model;
    }

    /**
     * The message the summary is stored under and the messages it covers.
     *
     * @return array{0: Message, 1: Collection<int, Message>}
     */
    private function target(Message $message, string $scope): array
    {
        if ($scope === AiSummary::SCOPE_THREAD && $message->thread_id) {
            $thread = Message::query()->where('account_id', $message->account_id)->where('thread_id', $message->thread_id)
                ->visible()->orderBy('received_at')->orderBy('id')->get();
            if ($thread->isNotEmpty()) {
                return [$thread->last(), $thread];
            }
        }

        return [$message, collect([$message])];
    }

    public function stored(Message $anchor, string $scope, int $count, string $model): ?AiSummary
    {
        return AiSummary::query()->where('message_id', $anchor->id)->where('scope', $scope)
            ->where('messages', $count)->where('model', $model)->first();
    }

    /** @param  Collection<int, Message>  $messages */
    public function write(AiSettings $settings, string $model, Message $anchor, string $scope, Collection $messages): AiSummary
    {
        $account = $anchor->account;
        $whole = $scope === AiSummary::SCOPE_THREAD && $messages->count() > 1;
        $me = $account->display_name ? "{$account->display_name} <{$account->email}>" : $account->email;
        $system = implode("\n", [
            $whole
                ? "You summarize an e-mail conversation for its reader, {$me}. Cover the whole conversation: how it started, what changed, where it stands now."
                : "You summarize one e-mail for its reader, {$me}.",
            ...self::instructions($settings),
            'Each line starts with "- ". Plain text.',
        ]);

        $client = new OllamaClient($settings->baseUrl, 8192, thinking: false);
        $text = $client->chat($model, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => self::input($messages, $account)],
        ], timeout: 240, temperature: 0.2);

        $lines = app(Proofreader::class)->fixLines(preg_split('/\r?\n/', $text) ?: [], $settings);
        $row = $this->store($anchor, $scope, $messages->count(), $model, $lines);
        if ($row === null) {
            throw new \RuntimeException('The model returned an empty summary.');
        }

        return $row;
    }
}
