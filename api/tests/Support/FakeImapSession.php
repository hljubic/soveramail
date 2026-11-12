<?php

namespace Tests\Support;

use App\Services\Imap\ImapSession;

/**
 * In-memory IMAP server: folders with UIDVALIDITY and messages keyed by UID.
 * Records every flag change and append so tests can assert on them.
 */
class FakeImapSession implements ImapSession
{
    /** @var array<string, array{uidvalidity: int, attributes: string[], delimiter: string, messages: array<int, array{raw: string, flags: string[], internal_date: ?string}>}> */
    public array $folders = [];

    /** @var array<int, array{path: string, uid: int, flags: string[], add: bool}> */
    public array $stores = [];

    /** @var array<int, array{path: string, raw: string, flags: string[]}> */
    public array $appends = [];

    public bool $reportAppendUid = true;

    public bool $closed = false;

    public function addFolder(string $path, array $attributes = [], int $uidvalidity = 1, string $delimiter = '/'): self
    {
        $this->folders[$path] = ['uidvalidity' => $uidvalidity, 'attributes' => $attributes, 'delimiter' => $delimiter, 'messages' => []];

        return $this;
    }

    public function put(string $path, int $uid, string $raw, array $flags = [], ?string $internalDate = null): self
    {
        $this->folders[$path]['messages'][$uid] = ['raw' => $raw, 'flags' => $flags, 'internal_date' => $internalDate];

        return $this;
    }

    public function folders(): array
    {
        return array_map(fn ($path, $f) => ['path' => $path, 'delimiter' => $f['delimiter'], 'attributes' => $f['attributes']], array_keys($this->folders), $this->folders);
    }

    public function status(string $path): array
    {
        $f = $this->folders[$path];
        $uids = array_keys($f['messages']);

        return ['uidvalidity' => $f['uidvalidity'], 'uidnext' => ($uids === [] ? 0 : max($uids)) + 1, 'messages' => count($uids)];
    }

    public function search(string $path, array $criteria): array
    {
        $messages = $this->folders[$path]['messages'];
        $uids = array_keys($messages);
        sort($uids);
        if (($criteria[0] ?? 'ALL') === 'UID') {
            [$from] = explode(':', $criteria[1]);
            $uids = array_values(array_filter($uids, fn ($u) => $u >= (int) $from));
        } elseif (($criteria[0] ?? '') === 'HEADER') {
            $uids = array_values(array_filter($uids, fn ($u) => str_contains($messages[$u]['raw'], (string) $criteria[2])));
        }

        return $uids; // SINCE: the fake keeps no dates, everything matches
    }

    public function fetch(string $path, array $uids): array
    {
        $out = [];
        foreach ($uids as $uid) {
            if (isset($this->folders[$path]['messages'][$uid])) {
                $out[$uid] = $this->folders[$path]['messages'][$uid];
            }
        }

        return $out;
    }

    public function flags(string $path, array $uids): array
    {
        $out = [];
        foreach ($uids as $uid) {
            if (isset($this->folders[$path]['messages'][$uid])) {
                $out[$uid] = $this->folders[$path]['messages'][$uid]['flags'];
            }
        }

        return $out;
    }

    public function store(string $path, int $uid, array $flags, bool $add): void
    {
        $this->stores[] = compact('path', 'uid', 'flags', 'add');
        $current = $this->folders[$path]['messages'][$uid]['flags'] ?? [];
        $this->folders[$path]['messages'][$uid]['flags'] = $add
            ? array_values(array_unique(array_merge($current, $flags)))
            : array_values(array_diff($current, $flags));
    }

    public function append(string $path, string $raw, array $flags): ?int
    {
        $uids = array_keys($this->folders[$path]['messages']);
        $uid = ($uids === [] ? 0 : max($uids)) + 1;
        $this->folders[$path]['messages'][$uid] = ['raw' => $raw, 'flags' => $flags, 'internal_date' => null];
        $this->appends[] = compact('path', 'raw', 'flags');

        return $this->reportAppendUid ? $uid : null;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
