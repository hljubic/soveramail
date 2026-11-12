<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How the owner of a mailbox writes, learned from their sent mail.
 *
 * @property array|null $stats
 */
class StyleProfile extends Model
{
    protected $fillable = [
        'account_id', 'enabled', 'stats', 'summary', 'summary_model', 'summary_built_at', 'summary_edited',
        'sample_count', 'analysed_until', 'built_at', 'queued_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'stats' => 'array',
            'summary_edited' => 'boolean',
            'sample_count' => 'integer',
            'summary_built_at' => 'datetime',
            'analysed_until' => 'datetime',
            'built_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isBuilt(): bool
    {
        return $this->built_at !== null && $this->sample_count > 0 && is_array($this->stats);
    }

    /** Usable in prompts: switched on and actually built. */
    public function isUsable(): bool
    {
        return $this->enabled && $this->isBuilt();
    }
}
