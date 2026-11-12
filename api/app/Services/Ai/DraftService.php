<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\Knowledge\KnowledgeContext;
use App\Services\Ai\Language\Languages;
use App\Services\Ai\Retrieval\EmbeddingService;
use App\Services\Ai\Style\DraftFeedbackService;
use App\Services\Ai\Style\StyleProfileService;
use App\Services\Mail\OwnText;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Compose with AI": one prompt over local context. Everything stays on the
 * machine (Ollama on localhost).
 *
 * Prompt layers (reply / forward):
 *  1. hidden system prompt (style, language, safety rules)              – ours
 *  2. the user's standing instructions (sidebar → "AI instructions")     – theirs
 *  2b. the writing-style profile learned from ALL of the user's sent mail
 *  3. style: the user's own earlier mails to this sender (other threads)
 *  4. related: similar earlier conversations (other threads), each with
 *     how the user answered back then
 *  5. the thread so far (oldest first), then the mail being answered
 *  6. what the user already typed + one-off instructions
 *
 * All sizes and switches come from RagSettings (AI instructions page).
 */
class DraftService
{
    /** @var (callable(string): void)|null told when a draft enters a later stage ("proofreading") */
    private $onStage = null;

    /** Streaming callers learn about the proofreading stage through this. */
    public function onStage(?callable $callback): static
    {
        $this->onStage = $callback;

        return $this;
    }

    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly StyleProfileService $profiles,
        private readonly DraftFeedbackService $feedback,
        private readonly Proofreader $proofreader,
    ) {}

    /**
     * @param  'reply'|'forward'  $kind
     * @return array{text: string, context: array<int, array{id: int, subject: ?string, from: ?string, date: ?string, score: float, mine: bool, reply_id: ?int}>}
     */
    public function draft(Message $original, string $kind, ?string $instructions = null, ?string $currentDraft = null): array
    {
        return $this->run($original, $kind, $instructions, $currentDraft, null);
    }

    /**
     * Same as draft() but $onDelta receives text chunks as the model produces them.
     * $onContext is called once, before generation, with the retrieval context.
     *
     * @param  callable(string): void  $onDelta
     * @param  callable(array): void|null  $onContext
     * @return array{text: string, context: array}
     */
    public function draftStream(Message $original, string $kind, callable $onDelta, ?callable $onContext = null, ?string $instructions = null, ?string $currentDraft = null): array
    {
        return $this->run($original, $kind, $instructions, $currentDraft, $onDelta, $onContext);
    }

    /**
     * Draft a brand-new e-mail from the user's notes (no original message).
     *
     * @param  array{to?: string[], subject?: ?string, current_draft?: ?string, instructions?: ?string}  $input
     * @param  callable(string): void|null  $onDelta
     * @param  callable(array): void|null  $onContext
     * @return array{text: string, context: array}
     */
    public function draftNew(Account $account, array $input, ?callable $onDelta = null, ?callable $onContext = null): array
    {
        $settings = self::readySettings();
        $rag = $settings->rag();

        $to = array_values(array_filter(array_map('trim', $input['to'] ?? [])));
        $subject = trim((string) ($input['subject'] ?? ''));
        $notes = trim((string) ($input['current_draft'] ?? ''));
        $instructions = trim((string) ($input['instructions'] ?? ''));

        if ($notes === '' && $instructions === '' && $subject === '') {
            throw new AiUnavailableException('Write a few words about what the e-mail should say first (or at least a subject).');
        }

        // Style: how the user writes to the first recipient; context: similar conversations for the topic.
        $firstRecipient = $to[0] ?? null;
        $recipientEmail = $firstRecipient ? strtolower(trim((string) preg_replace('/^.*<([^>]+)>.*$/', '$1', $firstRecipient))) : null;
        $styleExamples = $recipientEmail ? $this->recentMessagesTo($account, $recipientEmail, $rag) : [];
        $related = $this->related($account, trim("Subject: {$subject}\n\n{$notes}\n{$instructions}"), [], $rag);

        $parts = [];
        if ($profileBlock = $this->profileBlock($account, $rag)) {
            $parts[] = $profileBlock;
        }
        if ($knowledge = $this->knowledgeBlock($account, null, $to, $rag)) {
            $parts[] = $knowledge;
        }
        if ($styleExamples !== []) {
            $parts[] = $this->styleBlock($styleExamples, (string) $firstRecipient, $rag);
        }
        if ($related !== []) {
            $parts[] = $this->relatedBlock($related, $account, $rag);
        }

        $brief = "New e-mail to write\nTo: ".($to !== [] ? implode(', ', $to) : '(not set yet)')."\nSubject: ".($subject !== '' ? $subject : '(not set yet)');
        if ($notes !== '') {
            $brief .= "\n\nWhat the user wants to say (notes or a rough draft — turn this into the finished e-mail, keep every point):\n{$notes}";
        }
        if ($instructions !== '') {
            $brief .= "\n\nInstructions from the user for this draft: {$instructions}";
        }
        $parts[] = $brief;

        return $this->generate($settings, 'new', $account, implode("\n\n", $parts), $related, [
            'style_examples' => count($styleExamples),
        ], $onDelta, $onContext, null);
    }

    /** @return array{text: string, context: array} */
    private function run(Message $original, string $kind, ?string $instructions, ?string $currentDraft, ?callable $onDelta, ?callable $onContext = null): array
    {
        $settings = self::readySettings();
        $rag = $settings->rag();
        $account = $original->account;

        // The thread being answered: shown as conversation history, never used as
        // a style example or a "similar conversation" (that would be circular).
        $thread = $this->threadHistory($original, $rag);
        $threadIds = $rag->excludeThread ? $this->threadMessageIds($original) : [$original->id];

        $styleExamples = $this->recentRepliesToSender($original, $rag);
        $related = $this->related($account, $this->retrievalQuery($original, $thread), $threadIds, $rag);

        $parts = [];
        if ($profileBlock = $this->profileBlock($account, $rag)) {
            $parts[] = $profileBlock;
        }
        // A reply goes to the sender; a forward's recipient is not known yet.
        $people = $kind === 'reply' ? [(string) $original->from_email] : [];
        if ($knowledge = $this->knowledgeBlock($account, $original->thread_id, $people, $rag)) {
            $parts[] = $knowledge;
        }
        if ($styleExamples !== []) {
            $parts[] = $this->styleBlock($styleExamples, $original->from_name ?: (string) $original->from_email, $rag);
        }
        if ($related !== []) {
            $parts[] = $this->relatedBlock($related, $account, $rag, withDate: true);
        }
        if ($thread->isNotEmpty()) {
            $parts[] = $this->threadBlock($thread, $account, $rag);
        }

        $label = $kind === 'reply' ? 'E-mail to respond to' : 'E-mail to forward';
        $parts[] = "{$label}\n".$this->envelope($original, withDate: true)."\n\n".$this->excerpt($original, $rag->maxOriginalChars);

        if ($currentDraft !== null && trim($currentDraft) !== '') {
            $parts[] = "The user already started writing this. Keep its intent and wording where possible, improve and complete it:\n".trim($currentDraft);
        }
        if ($instructions !== null && trim($instructions) !== '') {
            $parts[] = 'Instructions from the user for this draft: '.trim($instructions);
        }

        return $this->generate($settings, $kind, $account, implode("\n\n", $parts), $related, [
            'style_examples' => count($styleExamples),
            'thread_messages' => $thread->count(),
        ], $onDelta, $onContext, $original->id);
    }

    /**
     * Call the model. Sizes only are logged, never content.
     *
     * @param  array<int, array{message: Message, score: float, mine: bool, reply: ?Message}>  $related
     * @return array{text: string, context: array}
     */
    private function generate(AiSettings $settings, string $kind, Account $account, string $user, array $related, array $stats, ?callable $onDelta, ?callable $onContext, ?int $originalId): array
    {
        $me = trim(($account->display_name ?? '').' <'.$account->email.'>');
        $system = self::systemPrompt($me, $kind, $settings->userPrompt, $settings->replyLanguage);

        $context = self::contextPayload($related);
        if ($onContext !== null) {
            $onContext($context);
        }

        logger()->info('AI draft prompt', $stats + [
            'kind' => $kind,
            'model' => $settings->model,
            'related' => count($related),
            'system_chars' => mb_strlen($system),
            'user_chars' => mb_strlen($user),
        ]);

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
        $numCtx = self::contextWindowFor($settings->rag()->contextTokens, mb_strlen($system) + mb_strlen($user));
        $client = new OllamaClient($settings->baseUrl, $numCtx, $settings->thinking);
        $temperature = $settings->rag()->temperature;
        // The user is waiting: background processing pauses meanwhile.
        $text = ModelRuntime::interactive(function () use ($client, $settings, $messages, $onDelta, $temperature) {
            // While a reasoning model thinks, the UI shows "Thinking…" (and the stream stays alive).
            $onThinking = $this->onStage !== null ? fn () => ($this->onStage)('thinking') : null;
            $text = $onDelta !== null
                ? $client->chatStream($settings->model, $messages, $onDelta, temperature: $temperature, onThinking: $onThinking)
                : $client->chat($settings->model, $messages, temperature: $temperature);
            if (trim($text) === '') {
                // Never hand back an empty draft as if it were one (the UI keeps what was typed).
                throw new \RuntimeException('The model returned an empty draft. Please try again.');
            }

            // Second pass: proofreading (invented words, wrong forms); the UI shows "Proofreading…".
            if ($this->proofreader->enabled($settings)) {
                if ($this->onStage !== null) {
                    ($this->onStage)('proofreading');
                }
                $text = $this->proofreader->fix($text, $settings);
            }

            return $text;
        });

        // Remembered so that, once the mail is sent, the user's edits can be learned from.
        $suggestion = trim($text) !== '' ? $this->feedback->remember($account, $kind, $originalId, (string) $settings->model, $text) : null;

        return ['text' => $text, 'context' => $context, 'suggestion_id' => $suggestion?->id];
    }

    /**
     * The context window for this prompt. With the default RAG sizes a reply with
     * related conversations, profile and thread is ~35k characters (~12k tokens in
     * Croatian), more than the configured 8192: Ollama would then silently cut the
     * start of the prompt (system rules, style profile). The window grows to fit,
     * up to 2× the setting (max 32k), so the retrieved knowledge actually reaches
     * the model; small prompts keep the configured size (no model reload, no RAM).
     */
    public static function contextWindowFor(int $configured, int $promptChars): int
    {
        // ~3 characters per token for typical mail text, plus room for the answer
        // (and the model's reasoning when thinking is on).
        $needed = (int) ceil($promptChars / 3) + 2048;
        if ($needed <= $configured) {
            return $configured;
        }
        $ceiling = min(32768, max($configured, $configured * 2));
        $window = min($ceiling, (int) (ceil($needed / 2048) * 2048));
        if ($needed > $window) {
            logger()->warning('AI draft prompt larger than the context window', ['needed_tokens' => $needed, 'num_ctx' => $window]);
        }

        return $window;
    }

    private static function readySettings(): AiSettings
    {
        $settings = AiSettings::load();
        if (! $settings->isReady()) {
            throw new AiUnavailableException('Local AI is not configured. Open "Configure AI" and pick an Ollama model.');
        }

        return $settings;
    }

    /**
     * The hidden system prompt. Not shown in the UI.
     */
    public static function systemPrompt(string $me, string $kind, string $userPrompt = '', ?string $replyLanguage = null): string
    {
        $lines = [
            'You are the private writing assistant inside the e-mail client of the user.',
            "The user is {$me}. You write e-mails in the user's name, in the user's own voice.",
            '',
            match ($kind) {
                'reply' => 'TASK: write the body of a reply to the e-mail marked "E-mail to respond to". "Conversation so far" is the same thread, oldest first: stay consistent with what was already said and do not repeat it.',
                'forward' => 'TASK: write a short note that introduces the e-mail marked "E-mail to forward" to a new recipient.',
                default => 'TASK: write a complete new e-mail from the user\'s notes marked "New e-mail to write". Keep every point the user listed; add only a fitting greeting and sign-off.',
            },
            '',
            'STYLE — imitate the user, not a generic assistant:',
            '- A block "How the user writes" (when present) is a profile learned from everything the user ever sent: greetings, sign-offs, signature, typical phrases, length, register. Follow it by default.',
            '- "Corrections the user made to earlier AI drafts" (inside that profile) are the most authoritative signal: never reuse a wording the user removed or replaced, and use what they replaced it with.',
            '- "What the user is and knows" (when present) holds the user\'s roles, projects, people and standing facts, how the user writes in this area, the usual answers to recurring questions and a card about the person written to. Use it for correct content (who is who, the facts the user always gives) and for the right address and tone; it never replaces honesty: anything not covered there or in the e-mails stays unknown.',
            '- Examples marked "written by the user" show how the user really writes to THIS person: greeting, sign-off, sentence length, formality, use of first names, humour or dryness. Match them closely; where they differ from the profile, the examples win. Do not copy their content, only the manner.',
            '- "Related earlier conversations" are other, separate threads from the user\'s mailbox. Use them for facts the user stated before and for how the user handled similar requests; never assume they are part of this conversation.',
            '- If there are no examples, be brief, warm and professional. No corporate filler, no "I hope this e-mail finds you well".',
            '',
            'LANGUAGE — this is critical:',
            $replyLanguage !== null
                ? '- Write in '.Languages::name($replyLanguage).' by default, in its STANDARD LITERARY form: '.Languages::standardForm($replyLanguage).'. Correct spelling, grammar and punctuation of the standard language; no dialect, no regionalisms, no slang and no anglicisms beyond what the user\'s own examples show. Only if the e-mail you are answering is clearly written in another language AND the user\'s examples to this same person are in that language, answer in that language instead — again in its standard written form.'
                : '- Reply in the language of the e-mail you are answering (for a new e-mail: the language of the user\'s notes), unless the user\'s examples to this same person are in another language; then use that one.',
            '- Closely related languages are DIFFERENT languages with different vocabulary and spelling: Croatian, Serbian, Bosnian and Montenegrin (e.g. Croatian: "tjedan, kruh, tisuću, što"; Serbian: "nedelja, hleb, hiljadu, šta"), Czech and Slovak, Norwegian and Danish, the Portuguese variants, etc. Detect which one the user writes in from the examples and use ONLY that one. Never mix them. Keep the user\'s variant and spelling (British vs American English).',
            '- Use the same script the user uses (Latin vs Cyrillic).',
            '',
            'RULES:',
            '- Output ONLY the e-mail body as plain text: no subject line, no markdown, no quotes of the original, no explanations, no placeholders like [Name].',
            '- Answer every question that was asked. Be specific; keep it as short as the situation allows.',
            '- Never invent facts, prices, dates, names or commitments. If information is missing, say the user will check and follow up.',
            '- Never mention that you are an AI or that you used earlier e-mails.',
            '- Sign the way the user signs in the examples (first name is the default).',
        ];

        $userPrompt = trim($userPrompt);
        if ($userPrompt !== '') {
            $lines[] = '';
            $lines[] = 'STANDING INSTRUCTIONS FROM THE USER (always follow these; they override the defaults above except the language and honesty rules):';
            $lines[] = $userPrompt;
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------- prompt blocks

    /** The learned writing-style profile of this mailbox, when switched on and built. */
    private function profileBlock(Account $account, RagSettings $rag): ?string
    {
        if (! $rag->useStyleProfile) {
            return null;
        }

        return $this->profiles->promptBlock($account, $rag->maxProfileChars);
    }

    /**
     * Who the user is, how they write in this conversation's area, their usual answers
     * there and who they are writing to (KnowledgeBuilder). Same switch as the profile.
     *
     * @param  string[]  $recipients
     */
    private function knowledgeBlock(Account $account, ?string $threadId, array $recipients, RagSettings $rag): ?string
    {
        if (! $rag->useStyleProfile) {
            return null;
        }

        return app(KnowledgeContext::class)->block($account, $threadId, $recipients);
    }

    /** @param  array<int, Message>  $examples */
    private function styleBlock(array $examples, string $who, RagSettings $rag): string
    {
        $txt = "How the user previously wrote to {$who} (written by the user — copy this style and language):\n\n";
        foreach ($examples as $i => $m) {
            $txt .= '--- Example '.($i + 1)." (written by the user) ---\nSubject: {$m->subject}\n".$this->excerpt($m, $rag->maxStyleChars)."\n\n";
        }

        return rtrim($txt);
    }

    /** @param  array<int, array{message: Message, score: float, mine: bool, reply: ?Message}>  $related */
    private function relatedBlock(array $related, Account $account, RagSettings $rag, bool $withDate = false): string
    {
        $txt = "Related earlier conversations from the user's mailbox (most similar first; other threads, not this one):\n\n";
        foreach ($related as $i => $item) {
            $m = $item['message'];
            $mine = $item['mine'] ? ' (written by the user)' : '';
            $txt .= '--- Conversation '.($i + 1)."{$mine} ---\n".$this->envelope($m, $withDate)."\n".$this->excerpt($m, $rag->maxContextChars)."\n";
            if ($item['reply'] !== null) {
                $r = $item['reply'];
                $txt .= $this->isMine($r, $account)
                    ? "\nHow the user answered (written by the user):\n"
                    : "\nWhat the user was answering to:\n";
                $txt .= $this->excerpt($r, $rag->maxContextChars)."\n";
            }
            $txt .= "\n";
        }

        return rtrim($txt)."\n=== End of related conversations ===";
    }

    /** @param  Collection<int, Message>  $thread */
    private function threadBlock(Collection $thread, Account $account, RagSettings $rag): string
    {
        $txt = "Conversation so far (this thread, oldest first; the newest message follows below as the one to answer):\n\n";
        foreach ($thread->values() as $i => $m) {
            $who = $this->isMine($m, $account) ? 'the user (written by the user)' : $this->fromLine($m);
            $date = ($m->sent_at ?? $m->received_at)?->toDayDateTimeString() ?? '';
            $txt .= '--- Message '.($i + 1)." — from {$who}, {$date} ---\n".$this->excerpt($m, $rag->maxThreadChars)."\n\n";
        }

        return rtrim($txt);
    }

    private function envelope(Message $m, bool $withDate): string
    {
        $line = 'From: '.$this->fromLine($m);
        if ($withDate) {
            $line .= "\nDate: ".(($m->sent_at ?? $m->received_at)?->toDayDateTimeString() ?? '');
        }

        return $line."\nSubject: {$m->subject}";
    }

    private function fromLine(Message $m): string
    {
        return $m->from_name ? "{$m->from_name} <{$m->from_email}>" : (string) $m->from_email;
    }

    /**
     * What the UI shows as "draft based on N similar conversations".
     *
     * @param  array<int, array{message: Message, score: float, mine: bool, reply: ?Message}>  $related
     */
    private static function contextPayload(array $related): array
    {
        return array_map(fn ($item) => [
            'id' => $item['message']->id,
            'subject' => $item['message']->subject,
            'from' => $item['message']->from_name ?: $item['message']->from_email,
            'date' => ($item['message']->sent_at ?? $item['message']->received_at)?->toIso8601String(),
            'score' => round($item['score'], 3),
            'mine' => $item['mine'],
            'reply_id' => $item['reply']?->id,
        ], $related);
    }

    private function excerpt(Message $m, int $max): string
    {
        $body = OwnText::of($m->body_text ?? $m->snippet ?? '');

        return mb_strlen($body) > $max ? mb_substr($body, 0, $max).' […]' : $body;
    }

    private function isMine(Message $m, Account $account): bool
    {
        return strtolower((string) $m->from_email) === strtolower((string) $account->email)
            || in_array('SENT', $m->label_ids ?? [], true);
    }

    // ---------------------------------------------------------------- the thread

    /**
     * Earlier messages of the same thread, oldest first (the original itself excluded).
     *
     * @return Collection<int, Message>
     */
    private function threadHistory(Message $original, RagSettings $rag): Collection
    {
        if ($rag->threadMessages <= 0 || ! $original->thread_id) {
            return new Collection;
        }

        return Message::query()
            ->where('account_id', $original->account_id)
            ->where('thread_id', $original->thread_id)
            ->where('id', '!=', $original->id)
            ->where('received_at', '<=', $original->received_at)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($rag->threadMessages)
            ->get()
            ->reverse()
            ->values();
    }

    /** @return int[] every local message id of the thread (at least the original) */
    private function threadMessageIds(Message $original): array
    {
        if (! $original->thread_id) {
            return [$original->id];
        }

        return Message::query()
            ->where('account_id', $original->account_id)
            ->where('thread_id', $original->thread_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * What we search similar conversations with: the mail being answered plus a
     * little of the preceding exchange, so a short "ok, and the price?" still
     * finds the conversation it belongs to.
     *
     * @param  Collection<int, Message>  $thread
     */
    private function retrievalQuery(Message $original, Collection $thread): string
    {
        $text = "Subject: {$original->subject}\nFrom: ".$this->fromLine($original)."\n\n".$this->excerpt($original, 2000);
        $previous = $thread->last();
        if ($previous instanceof Message) {
            $text .= "\n\nEarlier in the conversation:\n".$this->excerpt($previous, 600);
        }

        return $text;
    }

    // ---------------------------------------------------------------- style examples

    /**
     * The user's latest messages sent to the same person, from other threads:
     * the best possible style reference ("Reply Like Me").
     *
     * @return array<int, Message>
     */
    private function recentRepliesToSender(Message $original, RagSettings $rag): array
    {
        $sender = strtolower(trim((string) $original->from_email));
        if ($sender === '' || $sender === strtolower((string) $original->account->email)) {
            return [];
        }

        return $this->recentMessagesTo($original->account, $sender, $rag, $original->id, $rag->excludeThread ? $original->thread_id : null);
    }

    /**
     * The user's latest sent messages to one address.
     *
     * @return array<int, Message>
     */
    private function recentMessagesTo(Account $account, string $email, RagSettings $rag, ?int $excludeId = null, ?string $excludeThreadId = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || $rag->styleExamples <= 0) {
            return [];
        }

        // Recipient match is done in PHP: JSON "object in array" containment is not
        // portable across MySQL/SQLite, and a few hundred sent mails scan instantly.
        $recipientMatches = fn (Message $m): bool => collect(array_merge($m->to_recipients ?? [], $m->cc_recipients ?? []))
            ->contains(fn ($a) => strtolower((string) ($a['email'] ?? '')) === $email);

        return Message::query()
            ->where('account_id', $account->id)
            ->whereJsonContains('label_ids', 'SENT')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->when($excludeThreadId, fn ($q) => $q->where(fn ($qq) => $qq->whereNull('thread_id')->orWhere('thread_id', '!=', $excludeThreadId)))
            ->orderByDesc('received_at')
            ->limit(400)
            ->get()
            ->filter($recipientMatches)
            ->take($rag->styleExamples)
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- retrieval

    /**
     * Similar earlier conversations for a query text. Retrieval never fails a
     * draft: any error means "no context". Hits are collapsed to one per thread
     * and each one carries the user's reply from that thread, when there is one.
     *
     * @param  int[]  $excludeIds  messages that must not come back (the current thread)
     * @return array<int, array{message: Message, score: float, mine: bool, reply: ?Message}>
     */
    private function related(Account $account, string $query, array $excludeIds, RagSettings $rag): array
    {
        if ($rag->topK <= 0 || trim($query) === '') {
            return [];
        }

        try {
            // Over-fetch when grouping so that several mails of one thread still leave K threads.
            // ×2 on top: spam / trash / risky hits are dropped below.
            $fetch = ($rag->groupByThread ? $rag->topK * 4 : $rag->topK) * 2;
            $hits = $this->embeddings->similarToText($account, $query, $fetch, $excludeIds);
        } catch (Throwable $e) {
            logger()->info('Retrieval skipped', ['reason' => $e->getMessage()]);

            return [];
        }

        $out = [];
        $seenThreads = [];
        foreach ($hits as $hit) {
            if ($hit['score'] < $rag->minScore) {
                continue;
            }
            $m = $hit['message'];
            if (! self::usableAsContext($m)) {
                continue;
            }
            if ($rag->groupByThread && $m->thread_id) {
                if (isset($seenThreads[$m->thread_id])) {
                    continue;
                }
                $seenThreads[$m->thread_id] = true;
            }
            $mine = $this->isMine($m, $account);
            $out[] = [
                'message' => $m,
                'score' => $hit['score'],
                'mine' => $mine,
                'reply' => $rag->includeReplies ? $this->exchangePartner($m, $account, $mine) : null,
            ];
            if (count($out) >= $rag->topK) {
                break;
            }
        }

        return $out;
    }

    /**
     * Only mail the user kept is knowledge. Spam, trash, unsent drafts, mail gone
     * from the server and messages flagged as dangerous are left out: their text
     * reaches the prompt as "how the user handled this before", so a spam mail
     * with an IBAN or a link must never be able to steer a real reply.
     */
    private static function usableAsContext(Message $m): bool
    {
        if ($m->remote_deleted_at !== null || $m->is_risky) {
            return false;
        }
        $labels = $m->label_ids ?? [];

        return ! array_intersect(['SPAM', 'TRASH', 'DRAFT'], $labels);
    }

    /**
     * The other half of the exchange in that thread: the user's next reply to a
     * received mail, or the mail the user was answering when the hit is theirs.
     */
    private function exchangePartner(Message $hit, Account $account, bool $hitIsMine): ?Message
    {
        if (! $hit->thread_id) {
            return null;
        }

        $q = Message::query()
            ->where('account_id', $account->id)
            ->where('thread_id', $hit->thread_id)
            ->where('id', '!=', $hit->id);

        return $hitIsMine
            ? $q->where('received_at', '<=', $hit->received_at)->orderByDesc('received_at')->get()->first(fn (Message $m) => ! $this->isMine($m, $account))
            : $q->where('received_at', '>=', $hit->received_at)->orderBy('received_at')->get()->first(fn (Message $m) => $this->isMine($m, $account));
    }
}
