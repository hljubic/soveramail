<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = ['account_id', 'email', 'name', 'seen_count', 'sent_count', 'last_seen_at'];

    protected function casts(): array
    {
        return ['seen_count' => 'integer', 'sent_count' => 'integer', 'last_seen_at' => 'datetime'];
    }
}
