<?php

namespace App\Services\Imap;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Models\Label;
use App\Models\Message;
use App\Models\ProviderFolder;
use App\Services\Gmail\ParsedMessage;
use App\Services\Mail\Providers\MailProvider;
use App\Services\Sync\MessageStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Pulls mail from an IMAP server into the local store.
 *
 *  - full:        newest N messages of every folder (first run)
 *  - incremental: new UIDs above the per-folder cursor, flag changes, and
 *                 messages that vanished from a folder (hidden, never deleted)
 *  - backfill:    N older messages than the oldest we have (inbox + sent)
 *  - range:       everything newer than N days that we do not have yet
 *
 * Provider message id = "<folder path>::<UIDVALIDITY>::<UID>".
 */
class ImapSyncService
{
    private const RANGE_BUDGET_SECONDS = 600;

    /** Newest messages pulled from folders other than inbox/sent on the first run. */
    private const SECONDARY_FOLDER_LIMIT = 100;

    public function __construct(
        private readonly ImapSessionFactory $sessions,
        private readonly ImapMimeParser $parser,
        private readonly MessageStore $store,
    ) {}

    /** @return array{mode: string, added: int, updated: int, deleted: int, remaining: int} */
    public function sync(Account $account, string $mode, ?int $limit, ?int $sinceDays): array
    {
        $limit = max(1, min($limit ?? (int) config('sovera.sync.initial_limit', 100), (int) config('sovera.sync.max_limit', 2000)));
        $account->markSyncing();

        try {
            $session = $this->sessions->open($account);
            try {
                $folders = $this->syncFolders($account, $session);

                if ($mode === MailProvider::MODE_BACKFILL) {
                    $stats = $this->backfill($account, $session, $folders, $limit);
                } elseif ($mode === MailProvider::MODE_RANGE) {
                    $stats = $this->range($account, $session, $folders, max(0, (int) $sinceDays));
                } elseif ($mode === MailProvider::MODE_FULL || ! $account->initial_sync_done) {
                    $stats = $this->full($account, $session, $folders, $limit);
                } else {
                    $stats = $this->incremental($account, $session, $folders);
                }
            } finally {
                $session->close();
            }

            $account->markIdle();

            return $stats;
        } catch (ReauthRequiredException $e) {
            $account->markNeedsReauth($e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $account->markError($e->getMessage());
            throw $e;
        }
    }

    // ------------------------------------------------------------------ folders

    /**
     * Mirror the folder list as labels and make sure a cursor row exists per folder.
     *
     * @return array<int, ProviderFolder> selectable folders worth syncing
     */
    private function syncFolders(Account $account, ImapSession $session): array
    {
        $out = [];
        $seen = [];
        foreach ($session->folders() as $f) {
            $attrs = array_map('strtolower', $f['attributes']);
            if (in_array('\\noselect', $attrs, true) || in_array('\\nonexistent', $attrs, true)) {
                continue;
            }
            $role = self::role($f['path'], $attrs, $f['delimiter']);
            if ($role === null) {
                continue; // virtual duplicates such as Gmail's "All Mail" / "Starred" / "Important"
            }

            $folder = ProviderFolder::query()->firstOrCreate(['account_id' => $account->id, 'path' => $f['path']], ['role' => $role]);
            if ($folder->role !== $role) {
                $folder->forceFill(['role' => $role])->save();
            }
            $out[] = $folder;

            $labelId = self::labelFor($folder);
            $seen[] = $labelId;
            Label::query()->updateOrCreate(
                ['account_id' => $account->id, 'provider_label_id' => $labelId],
                ['name' => $role === 'other' ? self::leaf($f['path'], $f['delimiter']) : ucfirst($role), 'type' => $role === 'other' ? 'user' : 'system', 'visible' => true],
            );
        }
        // Flag pseudo-labels the UI relies on.
        foreach (['UNREAD' => 'Unread', 'STARRED' => 'Starred'] as $id => $name) {
            $seen[] = $id;
            Label::query()->updateOrCreate(['account_id' => $account->id, 'provider_label_id' => $id], ['name' => $name, 'type' => 'system', 'visible' => false]);
        }
        if ($seen !== []) {
            Label::query()->where('account_id', $account->id)->whereNotIn('provider_label_id', $seen)->delete();
        }

        return $out;
    }

    /** @param  string[]  $attrs  lower-cased LIST attributes */
    public static function role(string $path, array $attrs, string $delimiter): ?string
    {
        foreach (['\\all' => null, '\\flagged' => null, '\\important' => null, '\\sent' => 'sent', '\\drafts' => 'drafts', '\\trash' => 'trash', '\\junk' => 'junk', '\\archive' => 'archive'] as $attr => $role) {
            if (in_array($attr, $attrs, true)) {
                return $role;
            }
        }
        $leaf = strtolower(self::leaf($path, $delimiter));
        if (strtolower($path) === 'inbox') {
            return 'inbox';
        }

        return match (true) {
            in_array($leaf, ['sent', 'sent items', 'sent mail', 'sent messages', 'poslano', 'poslane poruke'], true) => 'sent',
            in_array($leaf, ['drafts', 'draft', 'skice', 'nacrti'], true) => 'drafts',
            in_array($leaf, ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'smeće', 'otpad'], true) => 'trash',
            in_array($leaf, ['junk', 'spam', 'junk e-mail', 'bulk mail', 'neželjena pošta'], true) => 'junk',
            in_array($leaf, ['archive', 'archives', 'arhiva'], true) => 'archive',
            in_array($leaf, ['all mail', 'starred', 'important'], true) => null,
            default => 'other',
        };
    }

    private static function leaf(string $path, string $delimiter): string
    {
        $parts = $delimiter !== '' ? explode($delimiter, $path) : [$path];

        return (string) end($parts);
    }

    /** Gmail-style label id the UI understands for a folder. */
    public static function labelFor(ProviderFolder $folder): string
    {
        return match ($folder->role) {
            'inbox' => 'INBOX',
            'sent' => 'SENT',
            'drafts' => 'DRAFT',
            'trash' => 'TRASH',
            'junk' => 'SPAM',
            default => $folder->path,
        };
    }

    // ------------------------------------------------------------------ modes

    private function full(Account $account, ImapSession $session, array $folders, int $limit): array
    {
        $added = 0;
        $updated = 0;
        foreach ($folders as $folder) {
            $status = $session->status($folder->path);
            $uids = $session->search($folder->path, ['ALL']);
            $take = in_array($folder->role, ['inbox', 'sent'], true) ? $limit : min($limit, self::SECONDARY_FOLDER_LIMIT);
            $newest = array_slice($uids, -$take);

            [$a, $u] = $this->fetchAndStore($account, $session, $folder, $status['uidvalidity'], $newest);
            $added += $a;
            $updated += $u;

            $folder->forceFill(['uidvalidity' => $status['uidvalidity'], 'last_uid' => $uids === [] ? 0 : max($uids)])->save();
        }
        $account->forceFill(['initial_sync_done' => true])->save();

        return ['mode' => MailProvider::MODE_FULL, 'added' => $added, 'updated' => $updated, 'deleted' => 0, 'remaining' => 0];
    }

    private function incremental(Account $account, ImapSession $session, array $folders): array
    {
        $added = 0;
        $updated = 0;
        $hidden = 0;
        foreach ($folders as $folder) {
            $status = $session->status($folder->path);

            if ($folder->uidvalidity !== null && $folder->uidvalidity !== $status['uidvalidity']) {
                // The server renumbered the folder: everything we have from it is stale.
                $hidden += $this->store->hide($account, $this->storedIds($account, $folder)->all());
                $folder->forceFill(['uidvalidity' => $status['uidvalidity'], 'last_uid' => 0])->save();
            }

            // 1. New messages above the cursor.
            $new = array_values(array_filter($session->search($folder->path, ['UID', ($folder->last_uid + 1).':*']), fn ($u) => $u > $folder->last_uid));
            [$a, $u] = $this->fetchAndStore($account, $session, $folder, $status['uidvalidity'], $new);
            $added += $a;
            $updated += $u;

            // 2. Flags of what we already have, and what vanished (hidden, never deleted).
            $stored = $this->storedUids($account, $folder, $status['uidvalidity']);
            if ($stored !== []) {
                $present = array_flip($session->search($folder->path, ['ALL']));
                $gone = [];
                $check = [];
                foreach ($stored as $uid => $messageId) {
                    isset($present[$uid]) ? $check[] = $uid : $gone[] = self::id($folder, $status['uidvalidity'], $uid);
                }
                $hidden += $this->store->hide($account, $gone);
                $updated += $this->refreshFlags($account, $folder, $status['uidvalidity'], $session->flags($folder->path, $check));
            }

            $folder->forceFill(['uidvalidity' => $status['uidvalidity'], 'last_uid' => max($folder->last_uid, $new === [] ? 0 : max($new))])->save();
        }

        return ['mode' => MailProvider::MODE_INCREMENTAL, 'added' => $added, 'updated' => $updated, 'deleted' => $hidden, 'remaining' => 0];
    }

    private function backfill(Account $account, ImapSession $session, array $folders, int $limit): array
    {
        $added = 0;
        $updated = 0;
        foreach ($folders as $folder) {
            if (! in_array($folder->role, ['inbox', 'sent'], true)) {
                continue;
            }
            $status = $session->status($folder->path);
            $stored = $this->storedUids($account, $folder, $status['uidvalidity']);
            $oldest = $stored === [] ? PHP_INT_MAX : min(array_keys($stored));
            $older = array_values(array_filter($session->search($folder->path, ['ALL']), fn ($u) => $u < $oldest));
            [$a, $u] = $this->fetchAndStore($account, $session, $folder, $status['uidvalidity'], array_slice($older, -$limit));
            $added += $a;
            $updated += $u;
        }

        return ['mode' => MailProvider::MODE_BACKFILL, 'added' => $added, 'updated' => $updated, 'deleted' => 0, 'remaining' => 0];
    }

    private function range(Account $account, ImapSession $session, array $folders, int $sinceDays): array
    {
        $added = 0;
        $updated = 0;
        $remaining = 0;
        $deadline = microtime(true) + self::RANGE_BUDGET_SECONDS;
        $criteria = $sinceDays > 0 ? ['SINCE', now()->subDays($sinceDays)->format('j-M-Y')] : ['ALL'];
        foreach ($folders as $folder) {
            $status = $session->status($folder->path);
            $stored = $this->storedUids($account, $folder, $status['uidvalidity']);
            $candidates = array_values(array_filter($session->search($folder->path, $criteria), fn ($u) => ! isset($stored[$u])));
            [$a, $u, $r] = $this->fetchAndStore($account, $session, $folder, $status['uidvalidity'], $candidates, $deadline);
            $added += $a;
            $updated += $u;
            $remaining += $r;
            $folder->forceFill(['uidvalidity' => $status['uidvalidity'], 'last_uid' => max($folder->last_uid, $candidates === [] ? 0 : max($candidates))])->save();
        }

        return ['mode' => MailProvider::MODE_RANGE, 'added' => $added, 'updated' => $updated, 'deleted' => 0, 'remaining' => $remaining];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  int[]  $uids
     * @return array{0: int, 1: int, 2: int} [added, updated, remaining]
     */
    private function fetchAndStore(Account $account, ImapSession $session, ProviderFolder $folder, int $uidvalidity, array $uids, ?float $deadline = null): array
    {
        if ($uids === []) {
            return [0, 0, 0];
        }
        $added = 0;
        $updated = 0;
        $done = 0;
        $pauseMs = max(0, (int) config('sovera.sync.batch_pause_ms', 600));
        foreach (array_chunk($uids, (int) config('sovera.sync.batch_size', 25)) as $i => $chunk) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            if ($i > 0 && $pauseMs > 0) {
                Sleep::for($pauseMs)->milliseconds();
            }
            $failed = 0;
            foreach ($session->fetch($folder->path, $chunk) as $uid => $item) {
                try {
                    $parsed = $this->parse($account, $folder, $uidvalidity, (int) $uid, $item);
                    $this->store->store($account, $parsed) ? $added++ : $updated++;
                } catch (Throwable $e) {
                    // Skip the one bad message so last_uid can move past it; a whole
                    // failing batch is not a message problem and stops the run.
                    if (++$failed >= max(3, count($chunk))) {
                        throw $e;
                    }
                    logger()->warning('Skipped a message that could not be stored', ['account' => $account->id, 'folder' => $folder->path, 'uid' => $uid, 'error' => $e->getMessage()]);
                }
            }
            $done += count($chunk);
        }

        return [$added, $updated, max(0, count($uids) - $done)];
    }

    /** @param  array{raw: string, flags: string[], internal_date: ?string}  $item */
    public function parse(Account $account, ProviderFolder $folder, int $uidvalidity, int $uid, array $item): ParsedMessage
    {
        $labels = self::labels($folder, $item['flags']);
        $id = self::id($folder, $uidvalidity, $uid);
        $headers = $this->parser->headerMap($item['raw']);

        return $this->parser->parse($item['raw'], $id, $labels, $item['internal_date'], $this->threadIdFor($account, $headers, $id));
    }

    /** @param  string[]  $flags */
    public static function labels(ProviderFolder $folder, array $flags): array
    {
        $flags = array_map('strtolower', $flags);
        $labels = [self::labelFor($folder)];
        if (! in_array('\\seen', $flags, true)) {
            $labels[] = 'UNREAD';
        }
        if (in_array('\\flagged', $flags, true)) {
            $labels[] = 'STARRED';
        }

        return $labels;
    }

    /**
     * Threads are not an IMAP concept. The thread id is derived from the
     * headers alone, so it does not depend on the order folders are synced in:
     * the root of the References chain, else In-Reply-To, else the message's
     * own Message-ID. Every mail of one conversation resolves to the same root.
     */
    private function threadIdFor(Account $account, array $headers, string $fallback): string
    {
        $refs = array_values(array_filter(array_map('trim', preg_split('/\s+/', (string) ($headers['references'] ?? '')) ?: [])));
        $root = $refs[0] ?? trim((string) ($headers['in-reply-to'] ?? ''));
        if ($root === '') {
            $root = trim((string) ($headers['message-id'] ?? ''));
        }

        return mb_substr($root !== '' ? 'mid:'.$root : $fallback, 0, 191);
    }

    public static function id(ProviderFolder $folder, int $uidvalidity, int $uid): string
    {
        return "{$folder->path}::{$uidvalidity}::{$uid}";
    }

    /** @return array{0: string, 1: int, 2: int}|null [path, uidvalidity, uid] */
    public static function parseId(string $providerMessageId): ?array
    {
        if (! preg_match('/^(.*)::(\d+)::(\d+)$/s', $providerMessageId, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2], (int) $m[3]];
    }

    /** @return Collection<int, string> provider ids of every stored message from this folder (any validity) */
    private function storedIds(Account $account, ProviderFolder $folder)
    {
        return Message::query()->where('account_id', $account->id)->whereRaw("provider_message_id LIKE ? ESCAPE '!'", [self::likePrefix($folder->path.'::')])->pluck('provider_message_id');
    }

    /** @return array<int, int> uid => message id for the current validity */
    private function storedUids(Account $account, ProviderFolder $folder, int $uidvalidity): array
    {
        $prefix = "{$folder->path}::{$uidvalidity}::";
        $out = [];
        Message::query()
            ->where('account_id', $account->id)
            ->whereRaw("provider_message_id LIKE ? ESCAPE '!'", [self::likePrefix($prefix)])
            ->whereNull('remote_deleted_at')
            ->pluck('id', 'provider_message_id')
            ->each(function ($id, $pid) use ($prefix, &$out): void {
                $uid = (int) substr($pid, strlen($prefix));
                if ($uid > 0) {
                    $out[$uid] = (int) $id;
                }
            });

        return $out;
    }

    /** @param  array<int, string[]>  $flags */
    private function refreshFlags(Account $account, ProviderFolder $folder, int $uidvalidity, array $flags): int
    {
        $updated = 0;
        foreach ($flags as $uid => $set) {
            $labels = self::labels($folder, $set);
            $message = Message::query()->where('account_id', $account->id)->where('provider_message_id', self::id($folder, $uidvalidity, (int) $uid))->first();
            if (! $message) {
                continue;
            }
            $current = array_values((array) $message->label_ids);
            sort($current);
            $wanted = $labels;
            sort($wanted);
            if ($current !== $wanted) {
                $message->forceFill(['label_ids' => $labels, 'is_unread' => in_array('UNREAD', $labels, true), 'is_starred' => in_array('STARRED', $labels, true)])->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * "starts with" pattern for LIKE. The escape character is explicit because
     * SQLite has none by default (a backslash only worked on MySQL), so folder
     * names with "_" or "%" matched nothing there.
     */
    private static function likePrefix(string $prefix): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
    }
}
