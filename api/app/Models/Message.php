<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected $fillable = [
        'account_id',
        'provider_message_id',
        'thread_id',
        'history_id',
        'message_id_header',
        'in_reply_to',
        'references_header',
        'subject',
        'from_name',
        'from_email',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'reply_to',
        'snippet',
        'body_text',
        'body_html',
        'headers',
        'label_ids',
        'is_unread',
        'is_starred',
        'has_attachments',
        'is_risky',
        'has_caution',
        'risk_acknowledged_at',
        'size_estimate',
        'sent_at',
        'received_at',
        'remote_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'bcc_recipients' => 'array',
            'reply_to' => 'array',
            'headers' => 'array',
            'label_ids' => 'array',
            'is_unread' => 'boolean',
            'is_starred' => 'boolean',
            'has_attachments' => 'boolean',
            'is_risky' => 'boolean',
            'has_caution' => 'boolean',
            'risk_acknowledged_at' => 'datetime',
            'urgency_at' => 'datetime',
            'size_estimate' => 'integer',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'remote_deleted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** Carries a dangerous attachment and the user has not yet confirmed they know. */
    public function isRiskLocked(): bool
    {
        return $this->is_risky && $this->risk_acknowledged_at === null;
    }

    public function localDraft(): HasOne
    {
        return $this->hasOne(LocalDraft::class);
    }

    /**
     * Messages Gmail reported as permanently deleted are never erased locally
     * (Sovera Mail does not delete mail); they are only hidden from every view.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('remote_deleted_at'));
    }

    /**
     * Virtual folders mapped onto Gmail system labels.
     */
    public function scopeInFolder(Builder $query, string $folder): Builder
    {
        $query->visible();

        return match ($folder) {
            'inbox' => $query->whereJsonContains('label_ids', 'INBOX'),
            'starred' => $query->where('is_starred', true),
            'unread' => $query->where('is_unread', true),
            'sent' => $query->whereJsonContains('label_ids', 'SENT'),
            'drafts' => $query->whereJsonContains('label_ids', 'DRAFT'),
            'important' => $query->whereJsonContains('label_ids', 'IMPORTANT'),
            'spam' => $query->whereJsonContains('label_ids', 'SPAM'),
            'trash' => $query->whereJsonContains('label_ids', 'TRASH'),
            'archive' => $query->whereJsonDoesntContain('label_ids', 'INBOX')
                ->whereJsonDoesntContain('label_ids', 'SENT')
                ->whereJsonDoesntContain('label_ids', 'DRAFT')
                ->whereJsonDoesntContain('label_ids', 'SPAM')
                ->whereJsonDoesntContain('label_ids', 'TRASH'),
            default => $query, // 'all'
        };
    }

    public function scopeWithLabel(Builder $query, string $providerLabelId): Builder
    {
        return $query->visible()->whereJsonContains('label_ids', $providerLabelId);
    }

    /**
     * Simple search. Uses MySQL FULLTEXT when available, LIKE elsewhere.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        if ($query->getConnection()->getDriverName() === 'mysql' && mb_strlen($term) >= 3) {
            // Boolean mode with prefix matching on each word.
            $words = preg_split('/\s+/', preg_replace('/[+\-><()~*"@]+/', ' ', $term)) ?: [];
            $boolean = implode(' ', array_map(fn ($w) => '+'.$w.'*', array_filter($words, fn ($w) => mb_strlen($w) >= 2)));
            if ($boolean !== '') {
                return $query->whereFullText(['subject', 'snippet', 'from_name', 'from_email', 'body_text'], $boolean, ['mode' => 'boolean']);
            }
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('subject', 'like', $like)
                ->orWhere('from_name', 'like', $like)
                ->orWhere('from_email', 'like', $like)
                ->orWhere('snippet', 'like', $like);
        });
    }
}
