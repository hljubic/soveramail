<?php

namespace App\Services\Ai;

/**
 * Second pass over text the local model wrote: drafts and summary lines. Small local
 * models sometimes invent words, borrow forms from a neighbouring language or get word
 * endings wrong outside English; a short, focused proofreading call catches most of it. Meaning, facts and layout must not change: an answer that looks like a rewrite
 * (much shorter or longer, empty) is thrown away and the first version is kept.
 */
class Proofreader
{
    public function enabled(AiSettings $settings): bool
    {
        return $settings->proofread && $settings->isReady();
    }

    public function fix(string $text, AiSettings $settings): string
    {
        $text = trim($text);
        if (! $this->enabled($settings) || $text === '' || mb_strlen($text) < 20) {
            return $text;
        }

        $client = new OllamaClient($settings->baseUrl, 8192, thinking: false);
        try {
            $answer = $client->chat((string) $settings->model, [
                ['role' => 'system', 'content' => self::prompt()],
                ['role' => 'user', 'content' => $text],
            ], timeout: 180, temperature: 0.1);
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            logger()->warning('Proofreading failed, keeping the first version', ['error' => $e->getMessage()]);

            return $text;
        }

        $fixed = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/', '', trim(ThinkTagFilter::strip($answer))));
        $ratio = mb_strlen($fixed) / max(1, mb_strlen($text));
        if ($fixed === '' || $ratio < 0.7 || $ratio > 1.4) {
            logger()->info('Proofreading answer looked like a rewrite, keeping the first version', ['ratio' => round($ratio, 2)]);

            return $text;
        }

        return $fixed;
    }

    /**
     * Summary lines: proofread together (one call), split again.
     *
     * @param  array<int, mixed>  $lines
     * @return string[]
     */
    public function fixLines(array $lines, AiSettings $settings): array
    {
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), $lines), fn ($l) => $l !== ''));
        if ($lines === [] || ! $this->enabled($settings)) {
            return $lines;
        }
        $fixed = $this->fix('- '.implode("\n- ", array_map(fn ($l) => ltrim($l, "-*• \t"), $lines)), $settings);
        $out = array_values(array_filter(array_map(fn ($l) => trim(ltrim(trim($l), '-*•')), preg_split('/\r?\n/', $fixed) ?: []), fn ($l) => $l !== ''));

        return $out !== [] ? $out : $lines;
    }

    public static function prompt(): string
    {
        return implode("\n", [
            'You are a careful proofreader. The text below was written by an AI model. Proofread it in the language it is written in.',
            'Fix only real mistakes: words that do not exist in that language, words or forms borrowed from a neighbouring language, wrong word endings and agreement, spelling, missing or wrong accents and diacritics, and punctuation.',
            'Keep everything else exactly: meaning, facts, names, numbers, dates, links, e-mail addresses, the greeting and sign-off, tone, line breaks and list markers. Do not add, remove, reorder or explain anything.',
            'Answer with the corrected text only. If nothing needs fixing, answer with the text unchanged.',
        ]);
    }
}
