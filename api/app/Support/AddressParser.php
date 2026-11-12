<?php

namespace App\Support;

/**
 * Minimal RFC 5322 address-list parser for already-decoded header values
 * (Gmail API hands us UTF-8, no encoded-words).
 */
final class AddressParser
{
    /**
     * @return array<int, array{name: ?string, email: string}>
     */
    public static function parseList(?string $header): array
    {
        $header = trim((string) $header);
        if ($header === '') {
            return [];
        }

        $out = [];
        foreach (self::splitTopLevel($header) as $chunk) {
            $addr = self::parseOne($chunk);
            if ($addr !== null) {
                $out[] = $addr;
            }
        }

        return $out;
    }

    /**
     * @return array{name: ?string, email: string}|null
     */
    public static function parseOne(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // "Display Name" <user@example.com>  /  Display Name <user@example.com>
        if (preg_match('/^(.*?)<([^<>]+)>\s*$/s', $value, $m)) {
            $email = strtolower(trim($m[2]));
            $name = self::cleanName($m[1]);

            return ['name' => $name !== '' ? $name : null, 'email' => $email];
        }

        // Bare address, possibly with a trailing comment: user@example.com (Name)
        if (preg_match('/^([^\s(]+@[^\s()]+)\s*(?:\((.*)\))?$/s', $value, $m)) {
            $name = isset($m[2]) ? self::cleanName($m[2]) : '';

            return ['name' => $name !== '' ? $name : null, 'email' => strtolower(trim($m[1]))];
        }

        // Anything else that vaguely looks like an address.
        if (preg_match('/[^\s<>,;"]+@[^\s<>,;"]+/', $value, $m)) {
            return ['name' => null, 'email' => strtolower(trim($m[0], " \t\n\r\0\x0B<>\"'"))];
        }

        return null;
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);
        $name = trim($name, " \t\r\n\"'");
        $name = stripslashes($name);

        return trim($name);
    }

    /**
     * Split on commas/semicolons that are not inside quotes or angle brackets.
     *
     * @return string[]
     */
    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $buf = '';
        $inQuote = false;
        $depth = 0;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $ch.$s[++$i];

                continue;
            }
            if ($ch === '"') {
                $inQuote = ! $inQuote;
            } elseif (! $inQuote && $ch === '<') {
                $depth++;
            } elseif (! $inQuote && $ch === '>') {
                $depth = max(0, $depth - 1);
            } elseif (! $inQuote && $depth === 0 && ($ch === ',' || $ch === ';')) {
                $parts[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $ch;
        }

        $parts[] = $buf;

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }
}
