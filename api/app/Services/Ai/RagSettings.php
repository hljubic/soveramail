<?php

namespace App\Services\Ai;

/**
 * Retrieval ("RAG") hyper-parameters for "Compose with AI". Everything the
 * prompt builder looks up at draft time lives here, so it can be tuned from
 * the AI instructions page without touching code.
 */
final class RagSettings
{
    public function __construct(
        /** Similar earlier conversations added to the prompt (0 = retrieval off). */
        public readonly int $topK = 5,
        /** The user's own earlier mails to the same person, as style examples. */
        public readonly int $styleExamples = 3,
        /** Earlier messages of the same thread shown as "Conversation so far". */
        public readonly int $threadMessages = 6,
        /** Drop similar conversations whose cosine score is below this (0 = keep all). */
        public readonly float $minScore = 0.0,
        /** The thread being answered is never a style example or a "similar conversation". */
        public readonly bool $excludeThread = true,
        /** One hit per thread instead of several mails from the same exchange. */
        public readonly bool $groupByThread = true,
        /** Show how the user answered inside each similar conversation. */
        public readonly bool $includeReplies = true,
        public readonly int $maxOriginalChars = 6000,
        public readonly int $maxContextChars = 1200,
        public readonly int $maxStyleChars = 900,
        public readonly int $maxThreadChars = 600,
        /** Sampling temperature for the drafting model. */
        public readonly float $temperature = 0.4,
        /** Prepend the learned writing-style profile (from all sent mail) to every draft. */
        public readonly bool $useStyleProfile = true,
        public readonly int $maxProfileChars = 6000,
        /** Ollama context window (tokens) for chat calls; its default 4096 would silently drop the start of long prompts. */
        public readonly int $contextTokens = 8192,
    ) {}

    public static function fromArray(array $raw): self
    {
        $d = new self;

        return new self(
            topK: (int) ($raw['top_k'] ?? $d->topK),
            styleExamples: (int) ($raw['style_examples'] ?? $d->styleExamples),
            threadMessages: (int) ($raw['thread_messages'] ?? $d->threadMessages),
            minScore: (float) ($raw['min_score'] ?? $d->minScore),
            excludeThread: (bool) ($raw['exclude_thread'] ?? $d->excludeThread),
            groupByThread: (bool) ($raw['group_by_thread'] ?? $d->groupByThread),
            includeReplies: (bool) ($raw['include_replies'] ?? $d->includeReplies),
            maxOriginalChars: (int) ($raw['max_original_chars'] ?? $d->maxOriginalChars),
            maxContextChars: (int) ($raw['max_context_chars'] ?? $d->maxContextChars),
            maxStyleChars: (int) ($raw['max_style_chars'] ?? $d->maxStyleChars),
            maxThreadChars: (int) ($raw['max_thread_chars'] ?? $d->maxThreadChars),
            temperature: (float) ($raw['temperature'] ?? $d->temperature),
            useStyleProfile: (bool) ($raw['use_style_profile'] ?? $d->useStyleProfile),
            maxProfileChars: (int) ($raw['max_profile_chars'] ?? $d->maxProfileChars),
            contextTokens: (int) ($raw['context_tokens'] ?? $d->contextTokens),
        );
    }

    public function with(array $changes): self
    {
        return self::fromArray($changes + $this->toArray());
    }

    /** Validation rules for the `rag.*` fields of PUT /ai/settings. */
    public static function rules(string $prefix = 'rag.'): array
    {
        return [
            $prefix.'top_k' => ['nullable', 'integer', 'min:0', 'max:20'],
            $prefix.'style_examples' => ['nullable', 'integer', 'min:0', 'max:10'],
            $prefix.'thread_messages' => ['nullable', 'integer', 'min:0', 'max:30'],
            $prefix.'min_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            $prefix.'exclude_thread' => ['nullable', 'boolean'],
            $prefix.'group_by_thread' => ['nullable', 'boolean'],
            $prefix.'include_replies' => ['nullable', 'boolean'],
            $prefix.'max_original_chars' => ['nullable', 'integer', 'min:500', 'max:30000'],
            $prefix.'max_context_chars' => ['nullable', 'integer', 'min:200', 'max:10000'],
            $prefix.'max_style_chars' => ['nullable', 'integer', 'min:200', 'max:10000'],
            $prefix.'max_thread_chars' => ['nullable', 'integer', 'min:100', 'max:10000'],
            $prefix.'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            $prefix.'use_style_profile' => ['nullable', 'boolean'],
            $prefix.'max_profile_chars' => ['nullable', 'integer', 'min:500', 'max:20000'],
            $prefix.'context_tokens' => ['nullable', 'integer', 'min:2048', 'max:65536'],
        ];
    }

    public function toArray(): array
    {
        return [
            'top_k' => $this->topK,
            'style_examples' => $this->styleExamples,
            'thread_messages' => $this->threadMessages,
            'min_score' => $this->minScore,
            'exclude_thread' => $this->excludeThread,
            'group_by_thread' => $this->groupByThread,
            'include_replies' => $this->includeReplies,
            'max_original_chars' => $this->maxOriginalChars,
            'max_context_chars' => $this->maxContextChars,
            'max_style_chars' => $this->maxStyleChars,
            'max_thread_chars' => $this->maxThreadChars,
            'temperature' => $this->temperature,
            'use_style_profile' => $this->useStyleProfile,
            'max_profile_chars' => $this->maxProfileChars,
            'context_tokens' => $this->contextTokens,
        ];
    }
}
