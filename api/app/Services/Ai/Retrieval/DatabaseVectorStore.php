<?php

namespace App\Services\Ai\Retrieval;

use App\Models\Message;
use App\Models\MessageEmbedding;
use Illuminate\Support\Facades\DB;

/**
 * Brute-force cosine similarity over the account's vectors stored in MySQL.
 * Several vectors per message (its text in pieces); a message scores as its best piece.
 * Tens of thousands of 1024-d vectors scan in about a second.
 */
class DatabaseVectorStore implements VectorStore
{
    public function upsert(Message $message, string $model, string $contentHash, array $vector): void
    {
        $this->upsertChunks($message, $model, $contentHash, [$vector]);
    }

    public function upsertChunks(Message $message, string $model, string $contentHash, array $vectors): void
    {
        $vectors = $vectors === [] ? [[]] : array_values($vectors); // an empty vector marks "tried, not embeddable"
        DB::transaction(function () use ($message, $model, $contentHash, $vectors): void {
            MessageEmbedding::query()->where('message_id', $message->id)->where('chunk', '>=', count($vectors))->delete();
            foreach ($vectors as $chunk => $vector) {
                $normalised = self::normalise($vector);
                MessageEmbedding::query()->updateOrCreate(
                    ['message_id' => $message->id, 'chunk' => $chunk],
                    [
                        'account_id' => $message->account_id,
                        'model' => $model,
                        'version' => MessageEmbedding::VERSION,
                        'dims' => count($normalised),
                        'content_hash' => $contentHash,
                        'vector' => MessageEmbedding::pack($normalised),
                    ],
                );
            }
        });
    }

    public function search(int $accountId, string $model, array $query, int $k, array $excludeMessageIds = [], array $onlyMessageIds = []): array
    {
        $q = self::normalise($query);
        $dims = count($q);
        if ($dims === 0 || $k <= 0) {
            return [];
        }

        $scored = [];

        MessageEmbedding::query()
            ->select(['message_id', 'vector', 'dims'])
            ->where('account_id', $accountId)   // privacy boundary
            ->where('model', $model)
            ->where('dims', $dims)
            ->when($excludeMessageIds !== [], fn ($qq) => $qq->whereNotIn('message_id', $excludeMessageIds))
            ->when($onlyMessageIds !== [], fn ($qq) => $qq->whereIn('message_id', $onlyMessageIds))
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$scored, $q): void {
                foreach ($rows as $row) {
                    $v = MessageEmbedding::unpack($row->vector);
                    $dot = 0.0;
                    foreach ($q as $i => $x) {
                        $dot += $x * ($v[$i] ?? 0.0);
                    }
                    $id = (int) $row->message_id;
                    if (! isset($scored[$id]) || $dot > $scored[$id]) {
                        $scored[$id] = $dot; // a message counts with its best piece
                    }
                }
            });

        arsort($scored);
        $out = [];
        foreach (array_slice($scored, 0, $k, true) as $id => $score) {
            $out[] = ['message_id' => $id, 'score' => $score];
        }

        return $out;
    }

    public function pendingMessageIds(int $accountId, string $model, int $limit): array
    {
        // Never indexed with this model, OR the message row changed after it was indexed
        // (a label change also bumps updated_at; the hash check in EmbeddingService then
        // skips the actual embedding when the text is unchanged).
        return Message::query()
            ->where('account_id', $accountId)
            ->whereNotExists(function ($sub) use ($model): void {
                $sub->select(DB::raw(1))
                    ->from('message_embeddings')
                    ->whereColumn('message_embeddings.message_id', 'messages.id')
                    ->where('message_embeddings.model', $model)
                    ->where('message_embeddings.chunk', 0)
                    ->where('message_embeddings.version', MessageEmbedding::VERSION)
                    ->whereColumn('message_embeddings.updated_at', '>=', 'messages.updated_at');
            })
            ->orderByDesc('received_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    public function contentHash(int $messageId, string $model): ?string
    {
        return MessageEmbedding::query()->where('message_id', $messageId)->where('model', $model)
            ->where('chunk', 0)->where('version', MessageEmbedding::VERSION)->value('content_hash');
    }

    public function touch(int $messageId, string $model): void
    {
        MessageEmbedding::query()->where('message_id', $messageId)->where('model', $model)->update(['updated_at' => now()]);
    }

    public function stats(int $accountId, string $model): array
    {
        return [
            'indexed' => MessageEmbedding::query()->where('account_id', $accountId)->where('model', $model)
                ->where('chunk', 0)->where('version', MessageEmbedding::VERSION)->count(),
            'total' => Message::query()->where('account_id', $accountId)->count(),
        ];
    }

    public function purgeOtherModels(int $accountId, string $model): int
    {
        return MessageEmbedding::query()->where('account_id', $accountId)->where('model', '!=', $model)->delete();
    }

    /** @param  float[]  $v */
    public static function normalise(array $v): array
    {
        $norm = 0.0;
        foreach ($v as $x) {
            $norm += $x * $x;
        }
        $norm = sqrt($norm);
        if ($norm <= 0.0) {
            return array_map('floatval', array_values($v));
        }

        return array_map(fn ($x) => $x / $norm, array_values($v));
    }
}
