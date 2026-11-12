<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Services\Sync\SyncProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'scopes' => $this->scopes ?? [],
            'can_modify' => $this->canModify(),
            // Servers only; the password never leaves the encrypted column.
            'imap' => $this->when($this->isImap() && $this->credentials, fn () => [
                'host' => $this->credentials['imap']['host'] ?? null, 'port' => $this->credentials['imap']['port'] ?? null,
                'encryption' => $this->credentials['imap']['encryption'] ?? null, 'username' => $this->credentials['imap']['username'] ?? null,
                'smtp_host' => $this->credentials['smtp']['host'] ?? null, 'smtp_port' => $this->credentials['smtp']['port'] ?? null,
                'smtp_encryption' => $this->credentials['smtp']['encryption'] ?? null,
            ]),
            'initial_sync_done' => $this->initial_sync_done,
            'sync_status' => $this->sync_status,
            'sync_error' => $this->sync_error,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            // Live "done / total" of a running fetch, and Gmail's own message count (null when unknown).
            'sync_progress' => SyncProgress::get($this->id),
            'mailbox_total' => SyncProgress::mailboxTotal($this->id),
            'messages_count' => $this->whenCounted('messages'),
            'unread_count' => $this->whenCounted('unread_count'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
