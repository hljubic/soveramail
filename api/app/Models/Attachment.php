<?php

namespace App\Models;

use App\Services\Mail\AttachmentRisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'message_id',
        'provider_attachment_id',
        'part_id',
        'filename',
        'mime_type',
        'size',
        'content_id',
        'is_inline',
        'risk_level',
        'risk_reason',
    ];

    /** Screened as dangerous: never served, previewed or forwarded. */
    public function isBlocked(): bool
    {
        return $this->risk_level === AttachmentRisk::DANGER;
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_inline' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
