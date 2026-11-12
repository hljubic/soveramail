<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sync cursor for one folder of a non-Gmail account: IMAP keeps UIDVALIDITY and
 * the highest synced UID.
 */
class ProviderFolder extends Model
{
    public const ROLES = ['inbox', 'sent', 'drafts', 'trash', 'junk', 'archive', 'other'];

    protected $fillable = ['account_id', 'path', 'role', 'uidvalidity', 'last_uid'];

    protected function casts(): array
    {
        return ['uidvalidity' => 'integer', 'last_uid' => 'integer'];
    }
}
