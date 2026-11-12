<?php

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * Where the local AI lives. Only Ollama for now; the shape is provider-agnostic
 * so llama.cpp / MLX / OpenAI-compatible servers can be added as adapters.
 */
final class AiSettings
{
    public const KEY = 'ai';

    public const DEFAULT_BASE_URL = 'http://host.docker.internal:11434';

    public readonly ?string $model;

    public readonly ?string $embeddingModel;

    public function __construct(
        public readonly string $provider,
        public readonly string $baseUrl,
        ?string $model,
        public readonly bool $enabled,
        ?string $embeddingModel = null,
        public readonly string $userPrompt = '',
        public readonly bool $streaming = true,
        public readonly bool $allowRemote = false,
        public readonly ?RagSettings $rag = null,
        /** Default language of AI drafts (ISO 639-1, see Languages::ALL); null = follow the incoming mail. */
        public readonly ?string $replyLanguage = null,
        /** 'auto' (detected from sent mail) | 'user' (chosen on the AI instructions page) | null (never set). */
        public readonly ?string $replyLanguageSource = null,
        public readonly ?string $replyLanguageDetectedAt = null,
        /** Last time the model was asked and could not decide (so it is not asked again right away). */
        public readonly ?string $replyLanguageCheckedAt = null,
        /**
         * Let reasoning models think before answering (drafts, smart search, the style guide,
         * language detection). Off = several times faster, and long background answers stay
         * inside their timeout; models that honour Ollama's think=false (e.g. Gemma 4) write
         * just as well. Sorting, summaries, knowledge and proofreading never think.
         */
        public readonly bool $thinking = false,
        /** Model that sorts mail into categories and judges urgency; null = the chat model. */
        ?string $classifyModel = null,
        /** Only conversations active in the last N days are sorted; null = the whole mailbox. */
        public readonly ?int $classifySinceDays = null,
        /** Model that writes "Summarize" summaries; null = the chat model. */
        ?string $summaryModel = null,
        /** Processing also writes "Summarize" summaries (the button always works). */
        public readonly bool $summariesInPass = true,
        /** ... but only for conversations / messages with at least this many words of their own. */
        public readonly int $summaryMinWords = 150,
        /** ... and never for newsletters, mailing lists and no-reply senders. */
        public readonly bool $summarySkipBulk = true,
        /** Second pass over AI drafts and summaries: a proofreading call fixes invented words and wrong forms. */
        public readonly bool $proofread = true,
    ) {
        // One spelling per model ("x" == "x:latest" for Ollama; the vector index is keyed by it).
        $this->model = self::normaliseModel($model);
        $this->embeddingModel = self::normaliseModel($embeddingModel);
        $this->classifyModel = self::normaliseModel($classifyModel);
        $this->summaryModel = self::normaliseModel($summaryModel);
    }

    public readonly ?string $classifyModel;

    public readonly ?string $summaryModel;

    /**
     * Context window of every chat-model call. One size for all: Ollama reloads the whole
     * model (15 GB, tens of seconds) whenever a request asks for a different num_ctx, which
     * made drafts wait behind reloads while processing ran. Big enough for the knowledge
     * build (24k) and the largest draft prompt (16k).
     */
    public const CHAT_CONTEXT = 24576;

    /** The model used for summaries (its own choice, else the chat model). */
    public function summarizationModel(): ?string
    {
        return $this->summaryModel ?? $this->model;
    }

    /** The model used for categories and urgency (its own choice, else the chat model). */
    public function classificationModel(): ?string
    {
        return $this->classifyModel ?? $this->model;
    }

    /** Categories and urgency need a running model, not necessarily the drafting one. */
    public function canClassify(): bool
    {
        return $this->enabled && $this->classificationModel() !== null && $this->baseUrl !== '';
    }

    /** Retrieval hyper-parameters (defaults when never saved). */
    public function rag(): RagSettings
    {
        return $this->rag ?? new RagSettings;
    }

    public static function load(): self
    {
        $raw = (array) Setting::get(self::KEY, []);

        return new self(
            provider: (string) ($raw['provider'] ?? 'ollama'),
            baseUrl: self::normaliseBaseUrl((string) ($raw['base_url'] ?? self::DEFAULT_BASE_URL)),
            model: self::normaliseModel($raw['model'] ?? null),
            enabled: (bool) ($raw['enabled'] ?? false),
            embeddingModel: self::normaliseModel($raw['embedding_model'] ?? null),
            userPrompt: trim((string) ($raw['user_prompt'] ?? '')),
            streaming: (bool) ($raw['streaming'] ?? true),
            allowRemote: (bool) ($raw['allow_remote'] ?? false),
            rag: RagSettings::fromArray((array) ($raw['rag'] ?? [])),
            replyLanguage: isset($raw['reply_language']) && $raw['reply_language'] !== '' ? (string) $raw['reply_language'] : null,
            replyLanguageSource: isset($raw['reply_language_source']) && $raw['reply_language_source'] !== '' ? (string) $raw['reply_language_source'] : null,
            replyLanguageDetectedAt: isset($raw['reply_language_detected_at']) && $raw['reply_language_detected_at'] !== '' ? (string) $raw['reply_language_detected_at'] : null,
            replyLanguageCheckedAt: isset($raw['reply_language_checked_at']) && $raw['reply_language_checked_at'] !== '' ? (string) $raw['reply_language_checked_at'] : null,
            thinking: (bool) ($raw['thinking'] ?? false),
            classifyModel: self::normaliseModel($raw['classify_model'] ?? null),
            classifySinceDays: isset($raw['classify_since_days']) && (int) $raw['classify_since_days'] > 0 ? (int) $raw['classify_since_days'] : null,
            summaryModel: self::normaliseModel($raw['summary_model'] ?? null),
            summariesInPass: (bool) ($raw['summaries_in_pass'] ?? true),
            summaryMinWords: max(0, (int) ($raw['summary_min_words'] ?? 150)),
            summarySkipBulk: (bool) ($raw['summary_skip_bulk'] ?? true),
            proofread: (bool) ($raw['proofread'] ?? true),
        );
    }

    public function save(): void
    {
        Setting::put(self::KEY, $this->toArray());
    }

    public function with(array $changes): self
    {
        return new self(
            provider: (string) ($changes['provider'] ?? $this->provider),
            baseUrl: self::normaliseBaseUrl((string) ($changes['base_url'] ?? $this->baseUrl)),
            model: array_key_exists('model', $changes) ? self::normaliseModel($changes['model']) : $this->model,
            enabled: (bool) ($changes['enabled'] ?? $this->enabled),
            embeddingModel: array_key_exists('embedding_model', $changes) ? self::normaliseModel($changes['embedding_model']) : $this->embeddingModel,
            userPrompt: array_key_exists('user_prompt', $changes) ? trim((string) $changes['user_prompt']) : $this->userPrompt,
            streaming: array_key_exists('streaming', $changes) ? (bool) $changes['streaming'] : $this->streaming,
            allowRemote: array_key_exists('allow_remote', $changes) ? (bool) $changes['allow_remote'] : $this->allowRemote,
            rag: is_array($changes['rag'] ?? null) ? $this->rag()->with($changes['rag']) : $this->rag(),
            replyLanguage: array_key_exists('reply_language', $changes) ? ($changes['reply_language'] !== '' && $changes['reply_language'] !== null ? (string) $changes['reply_language'] : null) : $this->replyLanguage,
            replyLanguageSource: array_key_exists('reply_language_source', $changes) ? ($changes['reply_language_source'] !== '' && $changes['reply_language_source'] !== null ? (string) $changes['reply_language_source'] : null) : $this->replyLanguageSource,
            replyLanguageDetectedAt: array_key_exists('reply_language_detected_at', $changes) ? ($changes['reply_language_detected_at'] !== '' && $changes['reply_language_detected_at'] !== null ? (string) $changes['reply_language_detected_at'] : null) : $this->replyLanguageDetectedAt,
            replyLanguageCheckedAt: array_key_exists('reply_language_checked_at', $changes) ? ($changes['reply_language_checked_at'] !== '' && $changes['reply_language_checked_at'] !== null ? (string) $changes['reply_language_checked_at'] : null) : $this->replyLanguageCheckedAt,
            thinking: array_key_exists('thinking', $changes) ? (bool) $changes['thinking'] : $this->thinking,
            classifyModel: array_key_exists('classify_model', $changes) ? self::normaliseModel($changes['classify_model']) : $this->classifyModel,
            classifySinceDays: array_key_exists('classify_since_days', $changes)
                ? ((int) $changes['classify_since_days'] > 0 ? (int) $changes['classify_since_days'] : null)
                : $this->classifySinceDays,
            summaryModel: array_key_exists('summary_model', $changes) ? self::normaliseModel($changes['summary_model']) : $this->summaryModel,
            summariesInPass: array_key_exists('summaries_in_pass', $changes) ? (bool) $changes['summaries_in_pass'] : $this->summariesInPass,
            summaryMinWords: array_key_exists('summary_min_words', $changes) ? max(0, (int) $changes['summary_min_words']) : $this->summaryMinWords,
            summarySkipBulk: array_key_exists('summary_skip_bulk', $changes) ? (bool) $changes['summary_skip_bulk'] : $this->summarySkipBulk,
            proofread: array_key_exists('proofread', $changes) ? (bool) $changes['proofread'] : $this->proofread,
        );
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->model !== null && $this->baseUrl !== '';
    }

    /** Retrieval (vector index) needs a separate embedding model. */
    public function canEmbed(): bool
    {
        return $this->enabled && $this->embeddingModel !== null && $this->baseUrl !== '';
    }

    /**
     * Ollama treats "bge-m3" and "bge-m3:latest" as the same model, but the vector index
     * is keyed by the exact string: one canonical spelling keeps a re-save from wiping it.
     */
    public static function normaliseModel(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return str_contains($name, ':') ? $name : $name.':latest';
    }

    /**
     * The API runs inside Docker: "localhost" there is the container, not the
     * user's machine, so rewrite it to the Docker host alias.
     */
    public static function normaliseBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return self::DEFAULT_BASE_URL;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://'.$url;
        }

        return (string) preg_replace('#^(https?://)(localhost|127\.0\.0\.1|0\.0\.0\.0)(?=[:/]|$)#i', '$1host.docker.internal', $url);
    }

    /**
     * Mail content is sent to this URL for inference, so it must stay on the
     * device or the private network unless the user explicitly opts out.
     */
    public static function assertLocal(string $url, ?bool $allowRemote = null): void
    {
        // Allowed either globally (AI_ALLOW_REMOTE env) or by the user's explicit opt-in in the AI settings.
        if (config('sovera.ai.allow_remote') || ($allowRemote ?? self::load()->allowRemote)) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new AiUnavailableException('Invalid model server URL.');
        }

        $local = in_array($host, ['localhost', 'host.docker.internal', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.localhost')
            || self::isPrivateIp($host);

        if (! $local) {
            throw new AiUnavailableException("\"{$host}\" is not a local address. Tick \"Allow a remote model server\" if you trust it: the e-mails you draft will be sent there for inference.");
        }
    }

    /**
     * Only loopback and private-address ranges are local. Link-local/reserved
     * targets (for example 169.254.169.254 cloud metadata) stay blocked.
     */
    private static function isPrivateIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($host);
            foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8'] as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($ip & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($host);

            return $host === '::1'
                || ($packed !== false && (ord($packed[0]) & 0xFE) === 0xFC); // fc00::/7 unique-local
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'base_url' => $this->baseUrl,
            'model' => $this->model,
            'enabled' => $this->enabled,
            'embedding_model' => $this->embeddingModel,
            'user_prompt' => $this->userPrompt,
            'streaming' => $this->streaming,
            'allow_remote' => $this->allowRemote,
            'rag' => $this->rag()->toArray(),
            'reply_language' => $this->replyLanguage,
            'reply_language_source' => $this->replyLanguageSource,
            'reply_language_detected_at' => $this->replyLanguageDetectedAt,
            'reply_language_checked_at' => $this->replyLanguageCheckedAt,
            'thinking' => $this->thinking,
            'classify_model' => $this->classifyModel,
            'classify_since_days' => $this->classifySinceDays,
            'summary_model' => $this->summaryModel,
            'summaries_in_pass' => $this->summariesInPass,
            'summary_min_words' => $this->summaryMinWords,
            'summary_skip_bulk' => $this->summarySkipBulk,
            'proofread' => $this->proofread,
        ];
    }
}
