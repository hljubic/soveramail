<?php

namespace App\Services\Ai\Style;

/**
 * Word-level diff between what the AI proposed and what the user actually sent.
 * Produces hunks ("replaced X with Y", "removed X", "added Y"), a similarity score
 * and a classification of each replacement (wording / spelling / diacritics / case),
 * plus greeting and sign-off changes. Pure PHP, no dependencies.
 */
final class TextDiff
{
    /** Longer texts are compared on their first N tokens (LCS is O(n·m)). */
    public const MAX_TOKENS = 1500;

    /**
     * @return array{
     *   similarity: float, tokens_before: int, tokens_after: int, same: int, removed: int, added: int,
     *   hunks: array<int, array{type: 'replace'|'remove'|'add', from: string, to: string, kind: string}>,
     *   greeting: ?array{from: string, to: string}, closing: ?array{from: string, to: string}
     * }
     */
    public static function compare(string $before, string $after): array
    {
        $a = self::tokensWithBreaks($before);
        $b = self::tokensWithBreaks($after);
        $n = count(self::tokens($before));
        $m = count(self::tokens($after));

        if ($n === 0 && $m === 0) {
            return self::result(1.0, 0, 0, 0, 0, 0, [], $before, $after);
        }
        if ($n > self::MAX_TOKENS || $m > self::MAX_TOKENS) {
            $a = array_slice($a, 0, self::MAX_TOKENS);
            $b = array_slice($b, 0, self::MAX_TOKENS);
        }

        $ops = self::diff($a, $b);

        $same = 0;
        $removed = 0;
        $added = 0;
        $hunks = [];
        $pendingFrom = [];
        $pendingTo = [];
        $flush = function () use (&$pendingFrom, &$pendingTo, &$hunks): void {
            if ($pendingFrom === [] && $pendingTo === []) {
                return;
            }
            $from = implode(' ', $pendingFrom);
            $to = implode(' ', $pendingTo);
            $type = $pendingFrom !== [] && $pendingTo !== [] ? 'replace' : ($pendingFrom !== [] ? 'remove' : 'add');
            $hunks[] = ['type' => $type, 'from' => $from, 'to' => $to, 'kind' => self::kind($type, $from, $to)];
            $pendingFrom = [];
            $pendingTo = [];
        };
        foreach ($ops as [$op, $token]) {
            if ($token === self::NL) {
                $flush(); // never merge edits across lines; breaks themselves are not counted

                continue;
            }
            if ($op === '=') {
                $flush();
                $same++;
            } elseif ($op === '-') {
                $removed++;
                $pendingFrom[] = $token;
            } else {
                $added++;
                $pendingTo[] = $token;
            }
        }
        $flush();

        $similarity = ($n + $m) > 0 ? round(2 * $same / ($n + $m), 4) : 1.0;

        return self::result($similarity, $n, $m, $same, $removed, $added, $hunks, $before, $after);
    }

    private static function result(float $similarity, int $n, int $m, int $same, int $removed, int $added, array $hunks, string $before, string $after): array
    {
        $firstA = self::firstLine($before);
        $firstB = self::firstLine($after);
        $lastA = self::lastLine($before);
        $lastB = self::lastLine($after);

        return [
            'similarity' => $similarity,
            'tokens_before' => $n,
            'tokens_after' => $m,
            'same' => $same,
            'removed' => $removed,
            'added' => $added,
            'hunks' => $hunks,
            'greeting' => $firstA !== $firstB && self::looksLikeGreeting($firstA) && self::looksLikeGreeting($firstB) ? ['from' => $firstA, 'to' => $firstB] : null,
            'closing' => $lastA !== $lastB && self::short($lastA) && self::short($lastB) ? ['from' => $lastA, 'to' => $lastB] : null,
        ];
    }

    /** Line break token: keeps hunks from spanning lines (a greeting swap stays separate from a deleted sentence). */
    public const NL = "\n";

    /** @return string[] words only (no line-break tokens) */
    public static function tokens(string $text): array
    {
        return array_values(array_filter(self::tokensWithBreaks($text), fn ($t) => $t !== self::NL));
    }

    /** @return string[] words plus one NL token per line break */
    private static function tokensWithBreaks(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $i => $line) {
            if ($i > 0) {
                $out[] = self::NL;
            }
            foreach (preg_split('/[ \t]+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                $out[] = $w;
            }
        }

        return $out;
    }

    /**
     * LCS-based diff. Returns a list of [op, token] with op in '=', '-', '+'.
     *
     * @param  string[]  $a
     * @param  string[]  $b
     * @return array<int, array{0: string, 1: string}>
     */
    private static function diff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // Trim the common prefix and suffix first: most edits are local.
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }
        $coreA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $coreB = array_slice($b, $prefix, $m - $prefix - $suffix);

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['=', $a[$i]];
        }
        foreach (self::lcsOps($coreA, $coreB) as $op) {
            $ops[] = $op;
        }
        for ($i = $n - $suffix; $i < $n; $i++) {
            $ops[] = ['=', $a[$i]];
        }

        return $ops;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return array_map(fn ($t) => ['+', $t], $b);
        }
        if ($m === 0) {
            return array_map(fn ($t) => ['-', $t], $a);
        }

        // dp[i][j] = LCS length of a[i..] and b[j..]
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            $ai = $a[$i];
            $row = &$dp[$i];
            $next = $dp[$i + 1];
            for ($j = $m - 1; $j >= 0; $j--) {
                $row[$j] = $ai === $b[$j] ? $next[$j + 1] + 1 : max($next[$j], $row[$j + 1]);
            }
            unset($row);
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['-', $a[$i]];
                $i++;
            } else {
                $ops[] = ['+', $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $ops[] = ['-', $a[$i]];
        }
        for (; $j < $m; $j++) {
            $ops[] = ['+', $b[$j]];
        }

        return $ops;
    }

    /** What sort of change a hunk is: wording, spelling, diacritics, case, punctuation, removed, added. */
    private static function kind(string $type, string $from, string $to): string
    {
        if ($type === 'remove') {
            return 'removed';
        }
        if ($type === 'add') {
            return 'added';
        }
        $f = self::bare($from);
        $t = self::bare($to);
        if ($f === $t) {
            return 'punctuation';
        }
        if (mb_strtolower($f) === mb_strtolower($t)) {
            return 'case';
        }
        if (self::stripDiacritics(mb_strtolower($f)) === self::stripDiacritics(mb_strtolower($t))) {
            return 'diacritics';
        }
        if (! str_contains($f, ' ') && ! str_contains($t, ' ') && max(mb_strlen($f), mb_strlen($t)) >= 4
            && levenshtein(self::ascii($f), self::ascii($t)) <= 2) {
            return 'spelling';
        }

        return 'wording';
    }

    private static function bare(string $s): string
    {
        return trim(preg_replace('/[\p{P}\p{S}]+/u', '', $s) ?? $s);
    }

    private static function stripDiacritics(string $s): string
    {
        return strtr($s, ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'd', 'Č' => 'C', 'Ć' => 'C', 'Š' => 'S', 'Ž' => 'Z', 'Đ' => 'D']);
    }

    private static function ascii(string $s): string
    {
        $s = self::stripDiacritics(mb_strtolower($s));

        return (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    }

    private static function firstLine(string $text): string
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return '';
    }

    private static function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));

        return $lines === [] ? '' : end($lines);
    }

    private static function looksLikeGreeting(string $line): bool
    {
        return $line !== '' && count(self::tokens($line)) <= 6;
    }

    private static function short(string $line): bool
    {
        return $line !== '' && count(self::tokens($line)) <= 6;
    }
}
