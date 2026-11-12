<?php

namespace App\Services\Ai\Knowledge;

use App\Models\Account;
use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Models\ThreadCategory;

/**
 * The part of the knowledge that matters for one draft, as a prompt block:
 * about the user (always), the conversation's category (how the user writes
 * there, recurring questions) and the people being written to.
 */
class KnowledgeContext
{
    private const BUDGET = [
        KnowledgeItem::KIND_ABOUT => 3200,
        KnowledgeItem::KIND_AUDIENCE => 1600,
        KnowledgeItem::KIND_FAQ => 1900,
        KnowledgeItem::KIND_CONTACT => 800,
    ];

    /** At most this many people get their card into one prompt. */
    private const MAX_PEOPLE = 2;

    /** @param  string[]  $recipientEmails */
    public function block(Account $account, ?string $threadId, array $recipientEmails, int $maxChars = 8000): ?string
    {
        $items = KnowledgeItem::query()->where('account_id', $account->id)->where('content', '!=', '')->get()
            ->keyBy(fn (KnowledgeItem $i) => $i->kind.'|'.$i->subject);
        if ($items->isEmpty()) {
            return null;
        }

        $parts = [];
        if ($about = $items->get(KnowledgeItem::KIND_ABOUT.'|')) {
            $parts[] = "About the user:\n".self::cut($about->content, self::BUDGET['about']);
        }

        $categoryId = $threadId ? ThreadCategory::query()->where('account_id', $account->id)->where('thread_id', $threadId)->value('category_id') : null;
        if ($categoryId && ($name = Category::query()->whereKey($categoryId)->value('name'))) {
            if ($a = $items->get(KnowledgeItem::KIND_AUDIENCE.'|'.$categoryId)) {
                $parts[] = "How the user writes in \"{$name}\" (this conversation's area):\n".self::cut($a->content, self::BUDGET['audience']);
            }
            if ($f = $items->get(KnowledgeItem::KIND_FAQ.'|'.$categoryId)) {
                $parts[] = "Questions that keep coming in \"{$name}\" and how the user usually answers:\n".self::cut($f->content, self::BUDGET['faq']);
            }
        }

        $me = strtolower((string) $account->email);
        $people = collect($recipientEmails)
            ->map(fn ($e) => strtolower(trim((string) preg_replace('/^.*<([^>]+)>.*$/', '$1', (string) $e))))
            ->filter(fn ($e) => $e !== '' && $e !== $me)->unique()->take(self::MAX_PEOPLE);
        foreach ($people as $email) {
            if ($c = $items->get(KnowledgeItem::KIND_CONTACT.'|'.$email)) {
                $parts[] = "About {$c->title} <{$email}> (who you are writing to):\n".self::cut($c->content, self::BUDGET['contact']);
            }
        }

        if ($parts === []) {
            return null;
        }

        $text = "What the user is and knows (learned from the user's own mailbox and checked by the user; use it for content and tone where it fits, never contradict it):\n\n".implode("\n\n", $parts);

        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars)).' […]' : $text;
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)).' […]' : $text;
    }
}
