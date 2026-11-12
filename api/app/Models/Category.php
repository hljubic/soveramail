<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A local category the user defined ("Clients", "Project Alpha", ...).
 * Exists only in Sovera Mail; Gmail never sees it.
 */
class Category extends Model
{
    protected $fillable = ['name', 'description', 'color', 'position', 'is_fallback'];

    protected function casts(): array
    {
        return ['is_fallback' => 'boolean', 'position' => 'integer'];
    }

    public function threads(): HasMany
    {
        return $this->hasMany(ThreadCategory::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('is_fallback')->orderBy('position')->orderBy('id');
    }

    public static function fallback(): self
    {
        return self::query()->where('is_fallback', true)->firstOrFail();
    }
}
