<?php

namespace App\Jobs;

use App\Exceptions\ReauthRequiredException;
use App\Models\Message;
use App\Services\Mail\MessageStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Mirrors a read / unread change of several messages to Gmail or the IMAP server
 * after the app already changed them locally (bulk "Mark as read / unread").
 */
class SyncMessageFlagsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /** @param  int[]  $messageIds */
    public function __construct(public readonly array $messageIds, public readonly string $label, public readonly bool $present) {}

    public function handle(MessageStateService $state): void
    {
        foreach (Message::query()->whereKey($this->messageIds)->get() as $message) {
            try {
                $state->pushFlag($message, $this->label, $this->present);
            } catch (ReauthRequiredException) {
                return; // the account shows "reconnect"; local state stays as the user chose
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
