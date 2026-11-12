<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalDraft extends Model
{
    protected $fillable = ['account_id', 'message_id', 'kind', 'to', 'cc', 'bcc', 'subject', 'body'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'bcc' => 'array'];
    }
}
