<?php

namespace App\Services\Mail\Providers;

use App\Exceptions\ReauthRequiredException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\ProviderFolder;
use App\Services\Gmail\ParsedMessage;
use App\Services\Imap\ImapConnectionException;
use App\Services\Imap\ImapMimeParser;
use App\Services\Imap\ImapSession;
use App\Services\Imap\ImapSessionFactory;
use App\Services\Imap\ImapSyncService;
use App\Services\Imap\SmtpSender;
use App\Services\Mail\RawMime;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Throwable;
use Webklex\PHPIMAP\Message as WebklexMessage;

/**
 * Plain IMAP (read, flags, drafts, sent copy) + SMTP (delivery) backend.
 * Covers iCloud, Yahoo, Fastmail, Zoho, GMX, hosting providers, Proton Bridge ...
 */
class ImapProvider implements MailProvider
{
    private ?ImapSession $session = null;

    public function __construct(
        private readonly ImapSessionFactory $sessions,
        private readonly ImapSyncService $sync,
        private readonly ImapMimeParser $parser,
        private readonly SmtpSender $smtp,
    ) {}

    public function __destruct()
    {
        $this->session?->close();
    }

    private function session(Account $account): ImapSession
    {
        return $this->session ??= $this->sessions->open($account);
    }

    public function sync(Account $account, string $mode = self::MODE_INCREMENTAL, ?int $limit = null, ?int $sinceDays = null): array
    {
        return $this->sync->sync($account, $mode, $limit, $sinceDays);
    }

    public function send(Account $account, Email $email, ?string $threadId): string
    {
        // The one call that puts mail on the wire.
        $this->smtp->send($account, $email);

        // Mirror into the Sent folder so every other client sees it too. Failing
        // here must not look like "not sent": the message is already out.
        try {
            return $this->appendTo($account, 'sent', $email, ['\\Seen']);
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('Message was sent but could not be stored locally.', 0, $e);
        }
    }

    public function saveDraft(Account $account, Email $email, ?string $threadId): string
    {
        return $this->appendTo($account, 'drafts', $email, ['\\Draft', '\\Seen']);
    }

    public function fetch(Account $account, string $providerMessageId): ?ParsedMessage
    {
        $parts = ImapSyncService::parseId($providerMessageId);
        if ($parts === null) {
            return null;
        }
        [$path, $validity, $uid] = $parts;
        $folder = ProviderFolder::query()->where('account_id', $account->id)->where('path', $path)->first();
        if (! $folder) {
            return null;
        }
        $item = $this->session($account)->fetch($path, [$uid])[$uid] ?? null;

        return $item ? $this->sync->parse($account, $folder, $validity, $uid, $item) : null;
    }

    public function setFlag(Account $account, Message $message, string $flag, bool $on): void
    {
        $parts = ImapSyncService::parseId($message->provider_message_id);
        if ($parts === null) {
            return;
        }
        [$path, , $uid] = $parts;
        // UNREAD is the *absence* of \Seen; STARRED is \Flagged. Nothing else is ever touched.
        match ($flag) {
            'UNREAD' => $this->session($account)->store($path, $uid, ['\\Seen'], ! $on),
            'STARRED' => $this->session($account)->store($path, $uid, ['\\Flagged'], $on),
            default => throw new \InvalidArgumentException("Refusing to change IMAP flag for \"{$flag}\"."),
        };
    }

    public function fetchAttachment(Account $account, Message $message, Attachment $attachment): ?string
    {
        $parts = ImapSyncService::parseId($message->provider_message_id);
        if ($parts === null || ! $attachment->provider_attachment_id) {
            return null;
        }
        [$path, , $uid] = $parts;
        $item = $this->session($account)->fetch($path, [$uid])[$uid] ?? null;
        if (! $item) {
            return null;
        }
        foreach (WebklexMessage::fromString($item['raw'])->getAttachments() as $a) {
            if ((string) $a->part_number === (string) $attachment->provider_attachment_id) {
                return (string) $a->content;
            }
        }

        return null;
    }

    /**
     * SMTP either accepted the message (250 after DATA) or answered with an error
     * code; a reply code means nothing was delivered. Only a dead connection
     * mid-transaction (code 0) is ambiguous.
     */
    public function definitelyRefused(Throwable $e): bool
    {
        if ($e instanceof ReauthRequiredException || $e instanceof ImapConnectionException) {
            return true;
        }
        if ($e instanceof TransportExceptionInterface) {
            return $e->getCode() >= 400 && $e->getCode() < 600;
        }

        return false;
    }

    private function appendTo(Account $account, string $role, Email $email, array $flags): string
    {
        $folder = ProviderFolder::query()->where('account_id', $account->id)->where('role', $role)->first();
        if (! $folder) {
            throw new RuntimeException("This mailbox has no {$role} folder.");
        }
        $session = $this->session($account);
        $raw = RawMime::withBcc($email);
        $uid = $session->append($folder->path, $raw, $flags);
        if ($uid === null) {
            // No APPENDUID: find the copy by its Message-ID.
            $messageId = $email->getHeaders()->get('Message-ID')?->getBodyAsString();
            $found = $messageId ? $session->search($folder->path, ['HEADER', 'Message-ID', $messageId]) : [];
            $uid = $found === [] ? null : max($found);
        }
        if ($uid === null) {
            throw new RuntimeException("Could not store the message in the {$role} folder.");
        }
        $validity = $session->status($folder->path)['uidvalidity'];
        // The folder cursor (last_uid / uidvalidity) is left alone on purpose: mail
        // another client put there since the last sync has lower UIDs than ours and
        // would be skipped for good. The next sync fetches our copy again; it is
        // stored under the same id, so it simply updates this row.

        return ImapSyncService::id($folder, $validity, $uid);
    }
}
