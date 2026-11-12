<?php

namespace App\Services\Mail\Providers;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Gmail\ParsedMessage;
use Symfony\Component\Mime\Email;

/**
 * Everything Sovera Mail needs from a mailbox backend. One implementation per
 * provider (Gmail API, IMAP/SMTP, ...); the safety layers above it (idempotent
 * sending, no-delete guarantee, attachment screening, local store) are shared.
 *
 * Implementations must never delete or move mail on the server.
 */
interface MailProvider
{
    public const MODE_FULL = 'full';

    public const MODE_INCREMENTAL = 'incremental';

    public const MODE_BACKFILL = 'backfill';

    public const MODE_RANGE = 'range';

    public const MODES = [self::MODE_INCREMENTAL, self::MODE_FULL, self::MODE_BACKFILL, self::MODE_RANGE];

    /**
     * Pull mail into the local store. Marks the account syncing/idle/error itself.
     *
     * @return array{mode: string, added: int, updated: int, deleted: int, remaining: int}
     */
    public function sync(Account $account, string $mode = self::MODE_INCREMENTAL, ?int $limit = null, ?int $sinceDays = null): array;

    /**
     * Deliver the message. Returns the provider id of the stored sent copy.
     * The caller owns the idempotency key; this must be the only network call
     * that can put mail on the wire.
     */
    public function send(Account $account, Email $email, ?string $threadId): string;

    /** Store the message as a draft (never sent). Returns the provider id of the draft. */
    public function saveDraft(Account $account, Email $email, ?string $threadId): string;

    /** The provider's copy of one message, e.g. to mirror a sent mail locally. Null when it is gone. */
    public function fetch(Account $account, string $providerMessageId): ?ParsedMessage;

    /**
     * Mirror read/unread or starred state. Only these two flags may ever change.
     *
     * @param  'UNREAD'|'STARRED'  $flag
     */
    public function setFlag(Account $account, Message $message, string $flag, bool $on): void;

    /** Raw attachment bytes. Only reached from an explicit user action. */
    public function fetchAttachment(Account $account, Message $message, Attachment $attachment): ?string;

    /** Did the failure happen before anything could have been delivered (4xx, auth)? */
    public function definitelyRefused(\Throwable $e): bool;
}
