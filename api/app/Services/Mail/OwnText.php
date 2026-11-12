<?php

namespace App\Services\Mail;

/**
 * The user's own words in a mail: body without quoted history, forwarded
 * blocks and "> " lines. Shared by the draft prompt and the style analyser.
 */
final class OwnText
{
    /**
     * Reply / forward headers that start the quoted part. Each one is matched at a
     * line start; the lead-in stays on one line ([^\n]) while the part after the date
     * may wrap once onto the next line, as Gmail does with long "Name <mail>" runs.
     */
    private const HEADERS = [
        'On [^\n]{5,200}?(?:\n[^\n]{0,120}?)?\swrote:',
        '-{2,} ?(?:Forwarded|Original) message ?-{2,}',
        '-{2,} ?(?:Proslijeđena|Izvorna) poruka ?-{2,}',
        '(?:Dana|Dne|Am|Le|El|Il) [^\n]{5,200}?(?:\n[^\n]{0,120}?)?\s(?:je\s)?napisa(?:o|la)(?:\sje)?:',
        '[^\n]{0,80}?\d{4}\.? (?:(?:u|at|um|à) )?\d{1,2}:\d{2}[^\n]{0,200}?(?:\n[^\n]{0,120}?)?\s(?:je\s)?napisa(?:o|la)(?:\sje)?:',
        '[^\n]{0,80}?\d{4}\.? (?:(?:u|at) )?\d{1,2}:\d{2}[^\n]{0,200}?(?:\n[^\n]{0,120}?)?\swrote:',
        'Am [^\n]{5,200}?(?:\n[^\n]{0,120}?)?\sschrieb:',
        'Le [^\n]{5,200}?(?:\n[^\n]{0,120}?)?\sa écrit ?:',
        'From: [^\n]{1,200}\n(?:Sent|Date): [^\n]{1,200}',
        '(?:Šalje|Od): [^\n]{1,200}\n(?:Poslano|Datum): [^\n]{1,200}',
    ];

    public static function of(?string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $body));
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        // Drop quoted history from replies so only what the user typed remains.
        $body = preg_replace('/(?:^|\n)(?:'.implode('|', self::HEADERS).').*$/su', '', $body) ?? $body;
        $lines = array_filter(explode("\n", $body), fn ($l) => ! str_starts_with(ltrim($l), '>'));

        return trim(implode("\n", $lines));
    }
}
