<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    public const PROVIDER_GMAIL = 'gmail';

    public const PROVIDER_IMAP = 'imap';

    public const STATUS_IDLE = 'idle';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_ERROR = 'error';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    /** Tokens removed by the user; the local mailbox copy is kept for a later reconnect. */
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'provider',
        'email',
        'display_name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'credentials',
        'history_id',
        'initial_sync_done',
        'sync_status',
        'sync_error',
        'last_synced_at',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'credentials'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'credentials' => 'encrypted:array',
            'initial_sync_done' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function styleProfile(): HasOne
    {
        return $this->hasOne(StyleProfile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    public const SCOPE_GMAIL_READONLY = 'https://www.googleapis.com/auth/gmail.readonly';

    public const SCOPE_GMAIL_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isImap(): bool
    {
        return $this->provider === self::PROVIDER_IMAP;
    }

    /** Can we read the mailbox at all? */
    public function canRead(): bool
    {
        if ($this->isImap()) {
            return $this->credentials !== null && ! $this->isDisconnected();
        }

        return $this->hasScope(self::SCOPE_GMAIL_MODIFY) || $this->hasScope(self::SCOPE_GMAIL_READONLY);
    }

    /** Can we mark read/unread, change labels and send? */
    public function canModify(): bool
    {
        if ($this->isImap()) {
            return $this->canRead();
        }

        return $this->hasScope(self::SCOPE_GMAIL_MODIFY);
    }

    protected function tokenExpired(): Attribute
    {
        return Attribute::get(fn (): bool => $this->token_expires_at === null || $this->token_expires_at->subMinute()->isPast());
    }

    public function markSyncing(): void
    {
        $this->forceFill(['sync_status' => self::STATUS_SYNCING, 'sync_error' => null])->save();
    }

    public function markIdle(): void
    {
        $this->forceFill([
            'sync_status' => self::STATUS_IDLE,
            'sync_error' => null,
            'last_synced_at' => now(),
        ])->save();
    }

    public function markError(string $message): void
    {
        $this->forceFill(['sync_status' => self::STATUS_ERROR, 'sync_error' => mb_substr($message, 0, 2000)])->save();
    }

    public function markNeedsReauth(string $message = 'Authorization expired. Please reconnect this account.'): void
    {
        $this->forceFill(['sync_status' => self::STATUS_NEEDS_REAUTH, 'sync_error' => $message])->save();
    }

    /**
     * Forget the Google grant but keep every message, attachment and draft on
     * disk. Reconnecting the same address picks the mailbox up where it was,
     * without downloading everything again.
     */
    public function disconnect(): void
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => [],
            'credentials' => null,
            'sync_status' => self::STATUS_DISCONNECTED,
            'sync_error' => null,
        ])->save();
    }

    public function isDisconnected(): bool
    {
        return $this->sync_status === self::STATUS_DISCONNECTED;
    }
}
