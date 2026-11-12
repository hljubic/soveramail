<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Ollama REST client (https://github.com/ollama/ollama/blob/main/docs/api.md).
 */
class OllamaClient
{
    /**
     * @param  ?int  $numCtx  context window passed to Ollama (its default of 4096 silently truncates long prompts)
     * @param  bool  $thinking  whether reasoning models may think first (see chatPayload)
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?int $numCtx = null,
        private readonly bool $thinking = true,
    ) {}

    private function http(int $timeout): PendingRequest
    {
        // Ollama endpoints never redirect. Refusing redirects prevents a trusted
        // local model URL from bouncing private mail content to a remote host.
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * @return array{version: ?string, models: array<int, array{name: string, size: int, family: ?string, parameters: ?string, quantization: ?string}>}
     */
    public function info(): array
    {
        try {
            $tags = $this->http(10)->get('/api/tags');
        } catch (ConnectionException $e) {
            throw new AiUnavailableException("Cannot reach Ollama at {$this->baseUrl}. Is it running? ({$e->getMessage()})");
        }
        if (! $tags->successful()) {
            throw new AiUnavailableException("Ollama at {$this->baseUrl} answered HTTP {$tags->status()}.");
        }

        $version = null;
        try {
            $v = $this->http(5)->get('/api/version');
            $version = $v->successful() ? ($v->json('version') ?? null) : null;
        } catch (ConnectionException) {
            // optional
        }

        $models = [];
        foreach ((array) $tags->json('models', []) as $m) {
            $details = (array) ($m['details'] ?? []);
            $name = (string) ($m['name'] ?? $m['model'] ?? '');
            $models[] = [
                'name' => $name,
                'size' => (int) ($m['size'] ?? 0),
                'family' => $details['family'] ?? null,
                'parameters' => $details['parameter_size'] ?? null,
                'quantization' => $details['quantization_level'] ?? null,
                'capabilities' => $this->capabilities($name),
            ];
        }
        usort($models, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['version' => $version, 'models' => $models];
    }

    /**
     * What a model can do ("completion", "embedding", "vision", ...). Older
     * Ollama versions do not report this; we then guess from the name.
     *
     * @return string[]
     */
    public function capabilities(string $model): array
    {
        try {
            $show = $this->http(5)->post('/api/show', ['model' => $model]);
            $caps = $show->successful() ? (array) $show->json('capabilities', []) : [];
        } catch (ConnectionException) {
            $caps = [];
        }
        if ($caps !== []) {
            return array_values(array_map('strval', $caps));
        }

        return str_contains(strtolower($model), 'embed') ? ['embedding'] : ['completion'];
    }

    /** @param  array<int, array{role: string, content: string}>  $messages */
    private function chatPayload(string $model, array $messages, bool $stream, float $temperature): array
    {
        $payload = ['model' => $model, 'messages' => $messages, 'stream' => $stream, 'options' => ['temperature' => $temperature], 'keep_alive' => self::keepAlive($model)];
        ModelRuntime::used($model);
        // Always the same window (AiSettings::CHAT_CONTEXT), whatever the caller asked for:
        // a different num_ctx makes Ollama reload the model. $numCtx only says a caller
        // needs a large window, which this one covers.
        $payload['options']['num_ctx'] = max(AiSettings::CHAT_CONTEXT, (int) $this->numCtx);

        // Reasoning models (qwen3, gemma4, deepseek-r1, gpt-oss, ...) get an explicit
        // think flag; "think" is rejected by non-reasoning models, so it is only sent
        // when the model reports the capability. think=true (default): Ollama returns
        // the reasoning in `message.thinking`, which we drop, and only the answer in
        // `message.content`. think=false is the user's opt-in for speed: hybrid models
        // (Gemma 4, Qwen3 hybrid) skip reasoning and answer several times faster, but
        // thinking-only builds (e.g. qwen3 "Thinking-2507") may ignore it and write the
        // reasoning into the content; inline <think> tags are still stripped.
        if (in_array('thinking', $this->capabilities($model), true)) {
            $payload['think'] = $this->thinking;
        }

        return $payload;
    }

    /**
     * Ollama keeps a model Sovera used in RAM until the user clicks "Unload" (keep_alive -1):
     * no countdown, nothing unloads on its own. The model param is kept for callers.
     */
    private static function keepAlive(string $model): int
    {
        return -1;
    }

    /**
     * Models in RAM right now (GET /api/ps): name, size, size in VRAM, expires_at.
     *
     * @return array<int, array{name: string, size: int, size_vram: int, expires_at: ?string}>
     */
    public function running(): array
    {
        try {
            $res = $this->http(5)->get('/api/ps');
        } catch (\Throwable) {
            return [];
        }
        if (! $res->successful()) {
            return [];
        }

        return array_values(array_map(fn ($m) => [
            'name' => (string) ($m['name'] ?? $m['model'] ?? ''),
            'size' => (int) ($m['size'] ?? 0),
            'size_vram' => (int) ($m['size_vram'] ?? 0),
            'expires_at' => isset($m['expires_at']) ? (string) $m['expires_at'] : null,
        ], (array) $res->json('models', [])));
    }

    /**
     * Take models out of RAM now (Ollama: keep_alive 0; a request in progress finishes
     * first). Only on the user's click ("Unload") or in tools. Never fails: an
     * unreachable server has nothing loaded.
     */
    public function unload(string ...$models): void
    {
        foreach (array_unique(array_filter($models)) as $model) {
            try {
                $this->http(15)->post('/api/generate', ['model' => $model, 'keep_alive' => 0]);
            } catch (\Throwable) {
                // nothing to do
            }
        }
    }

    /** 404 = model not pulled (actionable), any other failure = generic error. */
    private function assertOk(Response $response, string $notInstalledMessage, string $errorPrefix): void
    {
        if ($response->status() === 404) {
            throw new AiUnavailableException($notInstalledMessage);
        }
        if (! $response->successful()) {
            $err = $response->json('error') ?? $response->body();
            throw new RuntimeException("{$errorPrefix} ({$response->status()}): ".mb_substr((string) $err, 0, 300));
        }
    }

    /**
     * Embeddings for a batch of texts (POST /api/embed).
     *
     * @param  string[]  $inputs
     * @return array<int, float[]>
     */
    public function embed(string $model, array $inputs, int $timeout = 300): array
    {
        if ($inputs === []) {
            return [];
        }

        ModelRuntime::used($model);
        try {
            $response = $this->http($timeout)->post('/api/embed', [
                'model' => $model,
                'input' => array_values($inputs),
                'truncate' => true,
                'keep_alive' => self::keepAlive($model),
            ] + (ModelRuntime::used($model) ? [] : []) + [
            ]);
        } catch (ConnectionException $e) {
            throw $this->unreachable($e);
        }

        $this->assertOk($response, "Embedding model \"{$model}\" is not installed in Ollama. Run: ollama pull {$model}", 'Ollama embed error');

        return array_map(fn ($v) => array_map('floatval', (array) $v), (array) $response->json('embeddings', []));
    }

    /**
     * Streaming chat completion: $onDelta receives each content chunk as it
     * arrives (NDJSON from /api/chat with stream=true). Returns the full text.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     */
    /**
     * @param  (callable(): void)|null  $onThinking  called (at most every few seconds) while the
     *                                               model reasons before answering, so callers can
     *                                               show it and keep the connection alive
     */
    public function chatStream(string $model, array $messages, callable $onDelta, int $timeout = 300, float $temperature = 0.4, ?callable $onThinking = null): string
    {
        try {
            $response = $this->http($timeout)->withOptions(['stream' => true])->post('/api/chat', $this->chatPayload($model, $messages, true, $temperature));
        } catch (ConnectionException $e) {
            throw $this->unreachable($e);
        }

        $this->assertOk($response, "Model \"{$model}\" is not installed in Ollama. Run: ollama pull {$model}", 'Ollama error');

        $body = $response->toPsrResponse()->getBody();
        $full = '';
        $buffer = '';
        $filter = new ThinkTagFilter;

        $lastThinking = 0.0;
        $handleLine = function (string $line) use (&$full, &$lastThinking, $filter, $onDelta, $onThinking): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $json = json_decode($line, true);
            if (! is_array($json)) {
                return;
            }
            if (isset($json['error'])) {
                throw new RuntimeException('Ollama error: '.$json['error']);
            }
            // `message.thinking` (native reasoning) is never shown; only that it is happening.
            if ($onThinking !== null && ($json['message']['thinking'] ?? '') !== '' && microtime(true) - $lastThinking >= 5) {
                $lastThinking = microtime(true);
                $onThinking();
            }
            $delta = $filter->push((string) ($json['message']['content'] ?? ''), (bool) ($json['done'] ?? false));
            if ($delta === '') {
                return;
            }
            $full .= $delta;
            $onDelta($delta);
        };

        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($nl = strpos($buffer, "\n")) !== false) {
                $handleLine(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);
            }
        }
        if ($buffer !== '') {
            $handleLine($buffer);
        }

        return ltrim($full);
    }

    /**
     * Non-streaming chat completion. Returns the assistant text.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(string $model, array $messages, int $timeout = 180, float $temperature = 0.4): string
    {
        try {
            $response = $this->http($timeout)->post('/api/chat', $this->chatPayload($model, $messages, false, $temperature));
        } catch (ConnectionException $e) {
            throw $this->unreachable($e);
        }

        $this->assertOk($response, "Model \"{$model}\" is not installed in Ollama. Run: ollama pull {$model}", 'Ollama error');

        // Native reasoning lives in `message.thinking` and is ignored; models without
        // native support may still inline <think>…</think>, so strip that too.
        return trim(ThinkTagFilter::strip((string) ($response->json('message.content') ?? '')));
    }

    /**
     * Chat completion constrained to JSON (Ollama "structured outputs"). $schema is a
     * JSON schema array; the decoded object is returned, or null when the model
     * still produced something that is not valid JSON.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    public function chatJson(string $model, array $messages, array $schema, int $timeout = 60, float $temperature = 0.0): ?array
    {
        $payload = $this->chatPayload($model, $messages, false, $temperature) + ['format' => $schema];

        try {
            $response = $this->http($timeout)->post('/api/chat', $payload);
            // Some runners (Ollama's MLX engine, e.g. "gemma4:e4b") answer 501 to `format`:
            // ask again with the schema spelled out in the prompt instead.
            if ($response->status() === 501) {
                unset($payload['format']);
                $payload['messages'][] = ['role' => 'system', 'content' => 'Reply with one JSON object only, no other text, matching this JSON schema: '.json_encode($schema, JSON_UNESCAPED_UNICODE)];
                $response = $this->http($timeout)->post('/api/chat', $payload);
            }
        } catch (ConnectionException $e) {
            throw $this->unreachable($e);
        }

        $this->assertOk($response, "Model \"{$model}\" is not installed in Ollama. Run: ollama pull {$model}", 'Ollama error');

        $text = trim(ThinkTagFilter::strip((string) ($response->json('message.content') ?? '')));
        // Without `format` a model may add prose around the object: keep the outermost {...}.
        if (! str_starts_with($text, '{') && preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        // Some models wrap the object in a ```json fence despite the schema.
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** A timeout means "too slow", not "not running"; everything else is unreachable. */
    private function unreachable(ConnectionException $e): AiUnavailableException
    {
        if (preg_match('/timed out|cURL error 28/i', $e->getMessage())) {
            return new AiTimeoutException('The local model took too long to answer. Try again, pick a smaller model, or turn off "thinking" on the AI page.');
        }

        return new AiUnavailableException("Cannot reach Ollama at {$this->baseUrl}: {$e->getMessage()}");
    }
}
