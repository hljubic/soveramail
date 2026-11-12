<?php

namespace App\Services\Ai;

/**
 * Removes inline <think>…</think> reasoning from a streamed model answer.
 * Works token by token: a tag may arrive split across several chunks
 * ("<", "think", ">"), so anything that could be the start of a tag is
 * held back until it is known to be plain text.
 */
final class ThinkTagFilter
{
    private const OPEN = '<think>';

    private const CLOSE = '</think>';

    private string $held = '';

    private bool $inThink = false;

    /** Feed one chunk; returns the text that may be shown. */
    public function push(string $chunk, bool $done = false): string
    {
        $this->held .= $chunk;
        $out = '';

        while ($this->held !== '') {
            if ($this->inThink) {
                $pos = strpos($this->held, self::CLOSE);
                if ($pos === false) {
                    // Keep only a tail that could still be the start of the closing tag.
                    $this->held = self::possiblePrefix($this->held, self::CLOSE);

                    break;
                }
                $this->held = substr($this->held, $pos + strlen(self::CLOSE));
                $this->inThink = false;

                continue;
            }

            $pos = strpos($this->held, self::OPEN);
            if ($pos !== false) {
                $out .= substr($this->held, 0, $pos);
                $this->held = substr($this->held, $pos + strlen(self::OPEN));
                $this->inThink = true;

                continue;
            }

            // No tag: emit everything except a tail that could become "<think>".
            $keep = self::possiblePrefix($this->held, self::OPEN);
            $out .= substr($this->held, 0, strlen($this->held) - strlen($keep));
            $this->held = $keep;

            break;
        }

        if ($done && ! $this->inThink && $this->held !== '') {
            $out .= $this->held;
            $this->held = '';
        }

        return $out;
    }

    /** The longest suffix of $text that is a proper prefix of $tag (possibly ''). */
    private static function possiblePrefix(string $text, string $tag): string
    {
        $max = min(strlen($text), strlen($tag) - 1);
        for ($len = $max; $len > 0; $len--) {
            if (str_ends_with($text, substr($tag, 0, $len))) {
                return substr($text, -$len);
            }
        }

        return '';
    }

    /** Non-streaming variant. */
    public static function strip(string $text): string
    {
        return (new self)->push($text, true);
    }
}
