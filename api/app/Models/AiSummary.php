<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A stored "Summarize" result (see the migration). */
class AiSummary extends Model
{
    public const SCOPE_THREAD = 'thread';

    public const SCOPE_MESSAGE = 'message';

    protected $fillable = ['message_id', 'scope', 'messages', 'model', 'summary'];

    protected function casts(): array
    {
        return ['messages' => 'integer'];
    }
}
