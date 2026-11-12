<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Label extends Model
{
    protected $fillable = [
        'account_id',
        'provider_label_id',
        'name',
        'type',
        'color_bg',
        'color_fg',
        'visible',
    ];

    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }
}
