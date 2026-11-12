<?php

namespace App\Services\Ai\Retrieval;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\ModelRuntime;
use App\Services\Ai\OllamaClient;
use App\Services\Mail\OwnText;

/**
 * Turns messages into vectors (locally, via Ollama) and finds similar ones.
 */
class EmbeddingService
{
    /** Longest query text sent for a search. */
    public const MAX_CHARS = 3000;

    /** Size of one indexed piece of a message, and how many pieces at most. */
    public const CHUNK_CHARS = 2000;

    public const MAX_CHUNKS = 8;

    public const BATCH = 16;

    public function __construct(private readonly VectorStore $store) {}

    /**
     * The first piece of a message as embedded (envelope + start of its own text): what
     * "similar conversations" compares a message by.
     */
    public static function textFor(Message $message): string
    {
        return self::chunksFor($message)[0];
    }

    /**
     * The texts we embed for a message: its own words (quoted history of replies stripped;
     * a forwarded mail keeps what was forwarded, that is its content), cut at paragraph or
     * sentence ends into pieces of about CHUNK_CHARS, each with subject / sender / recipients
     * so a piece found alone still says what mail it belongs to. At most MAX_CHUNKS pieces.
     *
     * @return string[] never empty
     */
    public static function chunksFor(Message $message): array
    {
        $to = implode(', ', array_map(fn ($a) => $a['email'] ?? '', $message->to_recipients ?? []));
        $header = "Subject: {$message->subject}\nFrom: {$message->from_name} <{$message->from_email}>\nTo: {$to}\n\n";

        $raw = trim(str_replace(["\r\n", "\r"], "\n", (string) ($message->body_text ?? $message->snippet ?? '')));
        $forwarded = (bool) preg_match('/-{2,} ?(?:Forwarded|Original|Proslijeđena|Izvorna) (?:message|poruka) ?-{2,}/iu', $raw);
        $body = $forwarded
            ? trim(implode("\n", array_filter(explode("\n", $raw), fn ($l) => ! str_starts_with(ltrim($l), '>'))))
            : OwnText::of($raw);
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        $pieces = [];
        $rest = $body;
        while ($rest !== '' && count($pieces) < self::MAX_CHUNKS) {
            if (mb_strlen($rest) <= self::CHUNK_CHARS) {
                $pieces[] = $rest;
                break;
            }
            $window = mb_substr($rest, 0, self::CHUNK_CHARS);
            // Cut at the last paragraph break, else sentence end, else space, in the second half.
            $cut = null;
            foreach (["\n\n", '. ', "\n", ' '] as $sep) {
                $at = mb_strrpos($window, $sep);
                if ($at !== false && $at > self::CHUNK_CHARS / 2) {
                    $cut = $at + mb_strlen($sep);
                    break;
                }
            }
            $cut ??= self::CHUNK_CHARS;
            $pieces[] = trim(mb_substr($rest, 0, $cut));
            $rest = trim(mb_substr($rest, $cut));
        }

        return $pieces === [] ? [rtrim($header)] : array_map(fn ($p) => $header.$p, $pieces);
    }

    /**
     * Embed messages of one account that are not indexed yet.
     * Stops when the time budget is used up; returns how many are still pending.
     *
     * @return array{embedded: int, skipped: int, remaining: int}
     */
    public function indexAccount(Account $account, int $budgetSeconds = 540): array
    {
        $settings = AiSettings::load();
        if (! $settings->canEmbed()) {
            throw new AiUnavailableException('No embedding model configured. Open "Configure AI" and pick one (e.g. nomic-embed-text).');
        }

        $model = (string) $settings->embeddingModel;
        $client = new OllamaClient($settings->baseUrl);
        // Vectors of a previous model are only dropped once the new one has really
        // produced one: a typo or a model that is not pulled yet must not wipe an
        // index that took hours to build.
        $purged = false;

        $deadline = microtime(true) + $budgetSeconds;
        $embedded = 0;
        $skipped = 0;

        while (microtime(true) < $deadline) {
            ModelRuntime::yieldToUser();
            $ids = $this->store->pendingMessageIds($account->id, $model, self::BATCH);
            if ($ids === []) {
                break;
            }

            $messages = Message::query()->whereKey($ids)->get();

            // Skip messages whose text is unchanged (e.g. only labels/read state changed).
            $toEmbed = [];
            foreach ($messages as $message) {
                $chunks = self::chunksFor($message);
                $hash = hash('sha256', implode("\0", $chunks));
                if ($this->store->contentHash($message->id, $model) === $hash) {
                    $this->store->touch($message->id, $model);
                    $skipped++;

                    continue;
                }
                $toEmbed[] = [$message, $chunks, $hash];
            }
            if ($toEmbed === []) {
                continue;
            }

            // Every piece of the batch in one call, then back to their messages.
            $texts = [];
            foreach ($toEmbed as $i => [, $chunks]) {
                foreach ($chunks as $c => $text) {
                    $texts["{$i}:{$c}"] = $text;
                }
            }
            $flat = $this->embedBatch($client, $model, array_values($texts));
            $vectorsByKey = [];
            foreach (array_keys($texts) as $n => $key) {
                $vectorsByKey[$key] = $flat[$n] ?? [];
            }

            foreach ($toEmbed as $i => [$message, $chunks, $hash]) {
                $vectors = [];
                foreach (array_keys($chunks) as $c) {
                    $v = $vectorsByKey["{$i}:{$c}"] ?? [];
                    if ($v !== []) {
                        $vectors[] = $v;
                    }
                }
                if ($vectors !== [] && ! $purged) {
                    $this->store->purgeOtherModels($account->id, $model);
                    $purged = true;
                }
                // No vector at all marks a message Ollama cannot embed as done (dims 0
                // never matches a search), so it is not retried on every run.
                $this->store->upsertChunks($message, $model, $hash, $vectors);
                $vectors !== [] ? $embedded++ : $skipped++;
            }
        }

        $stats = $this->store->stats($account->id, $model);

        return ['embedded' => $embedded, 'skipped' => $skipped, 'remaining' => max(0, $stats['total'] - $stats['indexed'])];
    }

    /**
     * Messages from the same account most similar to $message.
     *
     * @return array<int, array{message: Message, score: float}>
     */
    public function similarTo(Message $message, int $k): array
    {
        return $this->similar($message->account_id, self::textFor($message), $k, [$message->id]);
    }

    /**
     * Messages of the account most similar to arbitrary text, skipping the given ids
     * (e.g. every message of the thread being answered).
     *
     * @param  int[]  $excludeMessageIds
     * @return array<int, array{message: Message, score: float}>
     */
    public function similarToText(Account $account, string $text, int $k, array $excludeMessageIds = []): array
    {
        return $this->similar($account->id, $text, $k, $excludeMessageIds);
    }

    /** @return array<int, array{message: Message, score: float}> */
    private function similar(int $accountId, string $text, int $k, array $excludeMessageIds = []): array
    {
        $settings = AiSettings::load();
        if (! $settings->canEmbed()) {
            return [];
        }
        $model = (string) $settings->embeddingModel;

        $vectors = (new OllamaClient($settings->baseUrl))->embed($model, [mb_substr($text, 0, self::MAX_CHARS)]);
        $query = $vectors[0] ?? null;
        if ($query === null) {
            return [];
        }

        $hits = $this->store->search($accountId, $model, $query, $k, $excludeMessageIds);
        if ($hits === []) {
            return [];
        }

        // Belt and braces: never cross accounts, whatever the store returned.
        $byId = Message::query()->where('account_id', $accountId)->whereKey(array_column($hits, 'message_id'))->get()->keyBy('id');

        $out = [];
        foreach ($hits as $hit) {
            if ($m = $byId->get($hit['message_id'])) {
                $out[] = ['message' => $m, 'score' => $hit['score']];
            }
        }

        return $out;
    }

    /**
     * Embed a batch; when Ollama rejects it (e.g. a NaN error on one odd text) the
     * texts are tried one by one so a single message cannot block the whole index.
     * Unreachable / missing model still throws AiUnavailableException.
     *
     * @param  string[]  $texts
     * @return array<int, float[]> same keys as $texts; [] for a text that failed
     */
    private function embedBatch(OllamaClient $client, string $model, array $texts): array
    {
        try {
            return $client->embed($model, $texts);
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            logger()->warning('Embedding batch failed, retrying one by one', ['error' => $e->getMessage()]);
        }

        $out = [];
        foreach ($texts as $i => $text) {
            try {
                $out[$i] = $client->embed($model, [$text])[0] ?? [];
            } catch (AiUnavailableException $e) {
                throw $e;
            } catch (\RuntimeException) {
                $out[$i] = [];
            }
        }

        return $out;
    }

    /** @return array{indexed: int, total: int} */
    public function stats(Account $account): array
    {
        $settings = AiSettings::load();
        if (! $settings->embeddingModel) {
            return ['indexed' => 0, 'total' => Message::query()->where('account_id', $account->id)->count()];
        }

        return $this->store->stats($account->id, $settings->embeddingModel);
    }
}
