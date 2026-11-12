<?php

namespace App\Services\Imap;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * ImapSession on top of webklex/php-imap's low-level protocol client.
 */
class WebklexImapSession implements ImapSession
{
    private ?string $selected = null;

    public function __construct(private readonly Client $client) {}

    private function protocol(): ImapProtocol
    {
        /** @var ImapProtocol $p */
        $p = $this->client->getConnection();

        return $p;
    }

    private function select(string $path): void
    {
        if ($this->selected !== $path) {
            $this->protocol()->selectFolder($path);
            $this->selected = $path;
        }
    }

    public function folders(): array
    {
        $out = [];
        foreach ($this->protocol()->folders()->data() as $path => $info) {
            if (! is_string($path)) {
                continue;
            }
            $out[] = [
                'path' => $path,
                'delimiter' => (string) ($info['delimiter'] ?? '/'),
                'attributes' => array_values(array_map('strval', (array) ($info['flags'] ?? []))),
            ];
        }

        return $out;
    }

    public function status(string $path): array
    {
        $s = $this->protocol()->folderStatus($path, ['MESSAGES', 'UIDNEXT', 'UIDVALIDITY'])->data();

        return [
            'uidvalidity' => (int) ($s['uidvalidity'] ?? 0),
            'uidnext' => (int) ($s['uidnext'] ?? 0),
            'messages' => (int) ($s['messages'] ?? 0),
        ];
    }

    public function search(string $path, array $criteria): array
    {
        $this->select($path);
        $ids = $this->protocol()->search($criteria)->data();
        $uids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : []), fn ($u) => $u > 0));
        sort($uids);

        return $uids;
    }

    public function fetch(string $path, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $this->select($path);
        $out = [];
        // BODY.PEEK[] is the full raw message *without* setting \Seen: reading a
        // mailbox must never change it (plain RFC822/BODY[] would mark mail read).
        // One FETCH per chunk keeps memory bounded.
        foreach (array_chunk(array_values($uids), 25) as $chunk) {
            $data = $this->protocol()->fetch(['BODY.PEEK[]', 'FLAGS', 'INTERNALDATE'], $chunk)->data();
            foreach ((array) $data as $uid => $item) {
                // Servers answer a PEEK with the BODY[] key; a few older ones echo RFC822.
                $raw = $item['BODY[]'] ?? $item['RFC822'] ?? null;
                if (! is_array($item) || $raw === null) {
                    continue;
                }
                $out[(int) $uid] = [
                    'raw' => (string) $raw,
                    'flags' => array_values(array_map('strval', (array) ($item['FLAGS'] ?? []))),
                    'internal_date' => isset($item['INTERNALDATE']) ? (string) $item['INTERNALDATE'] : null,
                ];
            }
        }

        return $out;
    }

    public function flags(string $path, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $this->select($path);
        $out = [];
        foreach (array_chunk(array_values($uids), 500) as $chunk) {
            foreach ((array) $this->protocol()->flags($chunk)->data() as $uid => $flags) {
                $out[(int) $uid] = array_values(array_map('strval', (array) $flags));
            }
        }

        return $out;
    }

    public function store(string $path, int $uid, array $flags, bool $add): void
    {
        $this->select($path);
        $this->protocol()->store($flags, $uid, null, $add ? '+' : '-');
    }

    public function append(string $path, string $raw, array $flags): ?int
    {
        $response = $this->protocol()->appendMessage($path, $raw, $flags);
        $lines = $response->data();
        $text = implode("\n", array_map(fn ($l) => is_array($l) ? implode(' ', $l) : (string) $l, is_array($lines) ? $lines : [$lines]));

        return preg_match('/APPENDUID\s+\d+\s+(\d+)/i', $text, $m) ? (int) $m[1] : null;
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
            // best effort
        }
    }
}
