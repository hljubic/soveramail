<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One piece of what the local AI knows about the user (see the migration for kinds).
 */
class KnowledgeItem extends Model
{
    public const KIND_ABOUT = 'about';

    public const KIND_AUDIENCE = 'audience';

    public const KIND_FAQ = 'faq';

    public const KIND_CONTACT = 'contact';

    public const SOURCE_AI = 'ai';

    public const SOURCE_USER = 'user';

    protected $fillable = ['account_id', 'kind', 'subject', 'title', 'content', 'source', 'model', 'evidence', 'built_at'];

    protected function casts(): array
    {
        return ['built_at' => 'datetime', 'evidence' => 'integer'];
    }
}
