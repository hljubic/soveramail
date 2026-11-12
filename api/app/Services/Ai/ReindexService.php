<?php

namespace App\Services\Ai;

use App\Jobs\ClassifyAccountJob;
use App\Jobs\EmbedAccountJob;
use App\Models\Account;
use App\Models\AiSummary;
use App\Models\KnowledgeItem;
use App\Models\Message;
use App\Models\MessageEmbedding;
use App\Models\ThreadCategory;
use App\Services\Ai\Classify\ThreadClassifier;
use App\Services\Ai\Style\StyleProfileService;

/**
 * "Rebuild everything" in one click: everything the local AI derived from the
 * mailbox is thrown away and built again in the background with the current
 * models and settings. What the user decided by hand (categories picked by hand,
 * corrections to drafts, the reply language they chose) is kept.
 */
class ReindexService
{
    public function __construct(private readonly StyleProfileService $profiles) {}

    /**
     * @return array{embeddings: int, categories: int, urgency: int, queued: string[]}
     */
    public function rebuild(Account $account): array
    {
        $settings = AiSettings::load();
        $queued = [];

        // Knowledge: the vector index (smart search, "similar conversations").
        $embeddings = MessageEmbedding::query()
            ->whereIn('message_id', Message::query()->where('account_id', $account->id)->select('id'))
            ->delete();
        if ($settings->canEmbed()) {
            EmbedAccountJob::dispatch($account->id);
            $queued[] = 'index';
        }

        // Categories the model chose (hand-picked ones stay) and urgency of unread mail.
        $categories = ThreadCategory::query()->where('account_id', $account->id)
            ->where('source', ThreadCategory::SOURCE_AI)->delete();
        $urgency = Message::query()->where('account_id', $account->id)->where('is_unread', true)
            ->whereNotNull('urgency')->update(['urgency' => null, 'urgency_at' => null]);
        if (ThreadClassifier::isActive($settings)) {
            ClassifyAccountJob::dispatch($account->id);
            $queued[] = 'categories';
        }

        // "Summarize" summaries: written again by "Process new mail" (unread) or on click.
        $summaries = AiSummary::query()
            ->whereIn('message_id', Message::query()->where('account_id', $account->id)->select('id'))
            ->delete();

        // What the AI knows about the user (hand-edited items stay).
        $knowledge = KnowledgeItem::query()->where('account_id', $account->id)->where('source', KnowledgeItem::SOURCE_AI)->delete();

        // Writing style: statistics from all sent mail plus the model-written guide;
        // its job queues the knowledge build when it is done.
        if ($settings->isReady()) {
            $this->profiles->queue($account);
            $queued[] = 'style';
            $queued[] = 'knowledge';
        }

        return ['embeddings' => $embeddings, 'categories' => $categories, 'urgency' => $urgency, 'knowledge' => $knowledge, 'summaries' => $summaries, 'queued' => $queued];
    }
}
