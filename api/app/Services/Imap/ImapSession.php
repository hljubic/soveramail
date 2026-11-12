<?php

namespace App\Services\Imap;

/**
 * The handful of IMAP operations Sovera Mail needs, behind an interface so the
 * sync and the provider can be tested against a fake server. Read-mostly by
 * design: there is no delete, move, copy or expunge here, on purpose.
 */
interface ImapSession
{
    /** @return array<int, array{path: string, delimiter: string, attributes: string[]}> */
    public function folders(): array;

    /** @return array{uidvalidity: int, uidnext: int, messages: int} */
    public function status(string $path): array;

    /**
     * UIDs matching an IMAP SEARCH, ascending. E.g. ['ALL'], ['UID', '100:*'], ['SINCE', '10-Sep-2026'].
     *
     * @return int[]
     */
    public function search(string $path, array $criteria): array;

    /**
     * Full RFC 822 bytes + flags for the given UIDs (missing ones are skipped).
     *
     * @param  int[]  $uids
     * @return array<int, array{raw: string, flags: string[], internal_date: ?string}> keyed by UID
     */
    public function fetch(string $path, array $uids): array;

    /**
     * @param  int[]  $uids
     * @return array<int, string[]> flags keyed by UID (missing UIDs absent)
     */
    public function flags(string $path, array $uids): array;

    /** Add (+FLAGS) or remove (-FLAGS) flags on one message. */
    public function store(string $path, int $uid, array $flags, bool $add): void;

    /** APPEND a message; returns the new UID when the server reports APPENDUID. */
    public function append(string $path, string $raw, array $flags): ?int;

    public function close(): void;
}
