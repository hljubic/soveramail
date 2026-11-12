<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable send receipt. A request key is claimed before Gmail is called, so a
 * repeated or ambiguous request can never send a second copy automatically.
 */
class OutgoingMailAttempt extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'account_id',
        'idempotency_key',
        'payload_hash',
        'status',
        'provider_message_id',
        'local_message_id',
        'error',
    ];
}
