<?php

namespace App\Services\Ai\Retrieval;

use App\Models\Message;

/**
 * Per-account vector index. Every method is scoped by account id so that
 * mailboxes can never bleed into each other's retrieval.
 */
interface VectorStore
{
    /** @param  float[]  $vector */
    public function upsert(Message $message, string $model, string $contentHash, array $vector): void;

    /**
     * All pieces of one message at once (replaces whatever was stored for it).
     *
     * @param  array<int, float[]>  $vectors  one per piece, in order
     */
    public function upsertChunks(Message $message, string $model, string $contentHash, array $vectors): void;

    /**
     * @param  float[]  $query
     * @param  int[]  $excludeMessageIds  never return these
     * @param  int[]  $onlyMessageIds  when non-empty, score only these (pre-filtered candidates)
     * @return array<int, array{message_id: int, score: float}> best first, one per message (its best piece)
     */
    public function search(int $accountId, string $model, array $query, int $k, array $excludeMessageIds = [], array $onlyMessageIds = []): array;

    /** Message ids of the account with no embedding for this model, one older than the message row, or one cut by an older version. */
    public function pendingMessageIds(int $accountId, string $model, int $limit): array;

    /** Content hash of the stored vector for this message+model, if any. */
    public function contentHash(int $messageId, string $model): ?string;

    /** Mark an existing vector as still valid (message changed, text did not). */
    public function touch(int $messageId, string $model): void;

    /** @return array{indexed: int, total: int} */
    public function stats(int $accountId, string $model): array;

    /** Drop vectors produced by a different model (after the user switches). */
    public function purgeOtherModels(int $accountId, string $model): int;
}
