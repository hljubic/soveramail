<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one main category of a conversation (thread). `source` = 'user' when the
 * user picked it by hand: the model never overrides that.
 */
class ThreadCategory extends Model
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_USER = 'user';

    protected $fillable = ['account_id', 'thread_id', 'category_id', 'source', 'model', 'classified_at'];

    protected function casts(): array
    {
        return ['classified_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
