<?php

namespace App\Services\Ai\Style;

use App\Models\Account;
use App\Models\AiSuggestion;
use App\Models\Message;

/**
 * Learns from what the user changes in AI drafts before sending them.
 *
 *  - every "Compose with AI" result is stored as an AiSuggestion,
 *  - when the mail is sent, the sent body is diffed against that suggestion,
 *  - the accumulated edits (replacements, removed and added wording, greeting and
 *    sign-off swaps, spelling) are aggregated into the style profile and shown to the
 *    model as the most authoritative signal of how the user wants drafts to read.
 */
class DraftFeedbackService
{
    /** Sent AI drafts considered when aggregating. */
    public const MAX_ROWS = 300;

    /** Above this similarity a draft counts as sent unchanged. */
    public const UNCHANGED = 0.97;

    /** A phrase in a hunk longer than this is a rewrite, not a reusable correction. */
    public const MAX_PHRASE_WORDS = 10;

    /**
     * Remember a draft the model produced.
     *
     * @param  'reply'|'forward'|'new'  $kind
     */
    public function remember(Account $account, string $kind, ?int $originalMessageId, string $model, string $text): AiSuggestion
    {
        return AiSuggestion::query()->create([
            'account_id' => $account->id,
            'message_id' => $originalMessageId,
            'kind' => $kind,
            'model' => $model,
            'suggestion' => $text,
        ]);
    }

    /**
     * The mail went out: compare the final body with the suggestion it started from.
     * Returns null when the id is unknown or belongs to another mailbox.
     */
    public function recordSend(Account $account, ?int $suggestionId, string $finalBody, ?Message $sent = null): ?AiSuggestion
    {
        if ($suggestionId === null) {
            return null;
        }
        $suggestion = AiSuggestion::query()->where('account_id', $account->id)->find($suggestionId);
        if ($suggestion === null) {
            return null;
        }

        $diff = TextDiff::compare($suggestion->suggestion, $finalBody);

        $suggestion->forceFill([
            'final_text' => $finalBody,
            'sent_message_id' => $sent?->id,
            'similarity' => $diff['similarity'],
            'stats' => [
                'tokens_before' => $diff['tokens_before'],
                'tokens_after' => $diff['tokens_after'],
                'same' => $diff['same'],
                'removed' => $diff['removed'],
                'added' => $diff['added'],
                'greeting' => $diff['greeting'],
                'closing' => $diff['closing'],
            ],
            'edits' => $diff['hunks'],
            'sent_at' => now(),
        ])->save();

        return $suggestion;
    }

    /** Sent AI drafts newer than $since (for staleness checks). */
    public function countSince(Account $account, ?\DateTimeInterface $since): int
    {
        return AiSuggestion::query()
            ->where('account_id', $account->id)
            ->whereNotNull('sent_at')
            ->when($since !== null, fn ($q) => $q->where('sent_at', '>', $since))
            ->count();
    }

    /**
     * What the user keeps changing, across all sent AI drafts of the mailbox.
     *
     * @return array{drafts: int, ...}|null null when nothing was sent from an AI draft yet
     */
    public function aggregate(Account $account): ?array
    {
        $rows = AiSuggestion::query()
            ->where('account_id', $account->id)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'similarity', 'stats', 'edits', 'sent_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $n = $rows->count();
        $unchanged = 0;
        $similaritySum = 0.0;
        $ratioSum = 0.0;
        $shortened = 0;
        $lengthened = 0;
        $replacements = [];
        $removed = [];
        $added = [];
        $greetings = [];
        $closings = [];
        $kinds = [];

        foreach ($rows as $row) {
            $sim = (float) $row->similarity;
            $similaritySum += $sim;
            if ($sim >= self::UNCHANGED) {
                $unchanged++;
            }
            $before = max(1, (int) ($row->stats['tokens_before'] ?? 1));
            $after = (int) ($row->stats['tokens_after'] ?? $before);
            $ratio = $after / $before;
            $ratioSum += $ratio;
            if ($ratio < 0.8) {
                $shortened++;
            } elseif ($ratio > 1.2) {
                $lengthened++;
            }
            $at = $row->sent_at?->toIso8601String();

            if (! empty($row->stats['greeting']['from']) || ! empty($row->stats['greeting']['to'])) {
                self::tally($greetings, $row->stats['greeting']['from'], $row->stats['greeting']['to'], 'greeting', $at);
            }
            if (! empty($row->stats['closing']['from']) || ! empty($row->stats['closing']['to'])) {
                self::tally($closings, $row->stats['closing']['from'], $row->stats['closing']['to'], 'closing', $at);
            }

            foreach ((array) $row->edits as $hunk) {
                $from = trim((string) ($hunk['from'] ?? ''));
                $to = trim((string) ($hunk['to'] ?? ''));
                $kind = (string) ($hunk['kind'] ?? 'wording');
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
                $fromWords = count(TextDiff::tokens($from));
                $toWords = count(TextDiff::tokens($to));
                if ($fromWords > self::MAX_PHRASE_WORDS || $toWords > self::MAX_PHRASE_WORDS) {
                    continue;
                }
                match ($hunk['type'] ?? '') {
                    'replace' => self::tally($replacements, $from, $to, $kind, $at),
                    'remove' => self::tally($removed, $from, '', 'removed', $at),
                    'add' => self::tally($added, '', $to, 'added', $at),
                    default => null,
                };
            }
        }

        return [
            'drafts' => $n,
            'unchanged_share' => round($unchanged / $n, 2),
            'avg_similarity' => round($similaritySum / $n, 3),
            'avg_length_ratio' => round($ratioSum / $n, 2),
            'shortened_share' => round($shortened / $n, 2),
            'lengthened_share' => round($lengthened / $n, 2),
            'edit_kinds' => $kinds,
            'replacements' => self::top($replacements, 25),
            'spelling' => self::top(array_filter($replacements, fn ($r) => in_array($r['kind'], ['spelling', 'diacritics', 'case'], true)), 10),
            'removed' => self::top($removed, 15),
            'added' => self::top($added, 15),
            'greetings' => self::top($greetings, 5),
            'closings' => self::top($closings, 5),
            'last_sent_at' => $rows->first()?->sent_at?->toIso8601String(),
        ];
    }

    private static function tally(array &$bucket, string $from, string $to, string $kind, ?string $at): void
    {
        $key = mb_strtolower(trim($from)).' → '.mb_strtolower(trim($to));
        if (! isset($bucket[$key])) {
            $bucket[$key] = ['from' => trim($from), 'to' => trim($to), 'kind' => $kind, 'count' => 0, 'last_at' => $at];
        }
        $bucket[$key]['count']++;
        if ($at !== null && ($bucket[$key]['last_at'] === null || $at > $bucket[$key]['last_at'])) {
            $bucket[$key]['last_at'] = $at;
        }
    }

    private static function top(array $bucket, int $limit): array
    {
        $rows = array_values($bucket);
        usort($rows, fn ($a, $b) => [$b['count'], (string) $b['last_at']] <=> [$a['count'], (string) $a['last_at']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * The prompt lines: what to do differently next time, most frequent first.
     */
    public static function describe(?array $f, int $maxItems = 12): array
    {
        if ($f === null || ($f['drafts'] ?? 0) === 0) {
            return [];
        }
        $pct = fn ($v) => (int) round(((float) $v) * 100).'%';
        $times = fn (int $c) => $c > 1 ? " ({$c}×)" : '';
        $lines = [];

        $head = "- Corrections the user made to {$f['drafts']} earlier AI draft".($f['drafts'] === 1 ? '' : 's')." before sending (the strongest signal — apply them): kept {$pct($f['avg_similarity'])} of the wording on average, sent {$pct($f['unchanged_share'])} unchanged";
        if (($f['shortened_share'] ?? 0) >= 0.3) {
            $head .= ", shortened {$pct($f['shortened_share'])} of them (write shorter)";
        } elseif (($f['lengthened_share'] ?? 0) >= 0.3) {
            $head .= ", lengthened {$pct($f['lengthened_share'])} of them (write more)";
        }
        $lines[] = $head.'.';

        $items = [];
        foreach (array_slice($f['greetings'] ?? [], 0, 2) as $g) {
            $items[] = "  greeting: \"{$g['from']}\" → \"{$g['to']}\"{$times($g['count'])}";
        }
        foreach (array_slice($f['closings'] ?? [], 0, 2) as $c) {
            $items[] = "  sign-off: \"{$c['from']}\" → \"{$c['to']}\"{$times($c['count'])}";
        }
        foreach (array_slice($f['replacements'] ?? [], 0, $maxItems) as $r) {
            $label = match ($r['kind']) {
                'diacritics', 'spelling', 'case' => 'spelling',
                'punctuation' => 'punctuation',
                default => 'wording',
            };
            $items[] = "  {$label}: \"{$r['from']}\" → \"{$r['to']}\"{$times($r['count'])}";
        }
        foreach (array_slice($f['removed'] ?? [], 0, 6) as $r) {
            $items[] = "  removes: \"{$r['from']}\"{$times($r['count'])}";
        }
        foreach (array_slice($f['added'] ?? [], 0, 6) as $a) {
            $items[] = "  adds: \"{$a['to']}\"{$times($a['count'])}";
        }

        return array_merge($lines, array_slice($items, 0, $maxItems + 6));
    }
}
