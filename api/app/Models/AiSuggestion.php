<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-composed draft and, after sending, what the user turned it into.
 *
 * @property array|null $stats
 * @property array|null $edits
 */
class AiSuggestion extends Model
{
    protected $fillable = ['account_id', 'message_id', 'kind', 'model', 'suggestion', 'final_text', 'sent_message_id', 'similarity', 'stats', 'edits', 'sent_at'];

    protected function casts(): array
    {
        return [
            'similarity' => 'float',
            'stats' => 'array',
            'edits' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
