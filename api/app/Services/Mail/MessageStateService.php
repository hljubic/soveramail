<?php

namespace App\Services\Mail;

use App\Exceptions\ReauthRequiredException;
use App\Models\Message;
use App\Services\Mail\Providers\MailProviders;
use Throwable;

/**
 * Read / unread (and starred) state: local first, then mirrored to Gmail
 * when the account has the gmail.modify scope.
 */
class MessageStateService
{
    public const REMOTE_OK = 'ok';

    public const REMOTE_SKIPPED = 'skipped';

    public const REMOTE_FAILED = 'failed';

    public function __construct(private readonly MailProviders $providers) {}

    /**
     * @return array{remote: string, remote_error: ?string}
     */
    /**
     * Read state of several messages: here at once, in Gmail / on the IMAP server by
     * SyncMessageFlagsJob (hundreds of provider calls would not fit in one request).
     *
     * @param  iterable<Message>  $messages
     * @return int[] ids that changed
     */
    public function setUnreadLocally(iterable $messages, bool $unread): array
    {
        $changed = [];
        foreach ($messages as $message) {
            if ((bool) $message->is_unread === $unread) {
                continue;
            }
            $labels = array_values(array_filter($message->label_ids ?? [], fn ($l) => $l !== 'UNREAD'));
            if ($unread) {
                $labels[] = 'UNREAD';
            }
            $message->forceFill(['is_unread' => $unread, 'label_ids' => $labels])->save();
            $changed[] = $message->id;
        }

        return $changed;
    }

    /** The provider side of a flag the app already changed locally (no local write). */
    public function pushFlag(Message $message, string $label, bool $present): void
    {
        $account = $message->account;
        if (! $account || ! $account->canModify()) {
            return;
        }
        try {
            $this->providers->for($account)->setFlag($account, $message, $label, $present);
        } catch (ReauthRequiredException $e) {
            $account->markNeedsReauth($e->getMessage());
            throw $e;
        }
    }

    public function setUnread(Message $message, bool $unread): array
    {
        return $this->setLabel($message, 'UNREAD', $unread, 'is_unread');
    }

    /**
     * @return array{remote: string, remote_error: ?string}
     */
    public function setStarred(Message $message, bool $starred): array
    {
        return $this->setLabel($message, 'STARRED', $starred, 'is_starred');
    }

    /**
     * @return array{remote: string, remote_error: ?string}
     */
    private function setLabel(Message $message, string $label, bool $present, string $flag): array
    {
        $labels = array_values(array_filter($message->label_ids ?? [], fn ($l) => $l !== $label));
        if ($present) {
            $labels[] = $label;
        }

        $message->forceFill([$flag => $present, 'label_ids' => $labels])->save();

        $account = $message->account;
        if (! $account->canModify()) {
            return ['remote' => self::REMOTE_SKIPPED, 'remote_error' => 'Reconnect the account to sync read state with Gmail.'];
        }

        try {
            $this->providers->for($account)->setFlag($account, $message, $label, $present);

            return ['remote' => self::REMOTE_OK, 'remote_error' => null];
        } catch (ReauthRequiredException $e) {
            $account->markNeedsReauth($e->getMessage());

            return ['remote' => self::REMOTE_FAILED, 'remote_error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['remote' => self::REMOTE_FAILED, 'remote_error' => $e->getMessage()];
        }
    }
}
