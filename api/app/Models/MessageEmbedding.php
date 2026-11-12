<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageEmbedding extends Model
{
    /**
     * How message text is cut into vectors. 1 = first 3000 characters with quoted history;
     * 2 = own text only, in pieces. Older rows still answer searches until replaced.
     */
    public const VERSION = 2;

    protected $fillable = ['account_id', 'message_id', 'chunk', 'version', 'model', 'dims', 'content_hash', 'vector'];

    /** @param  float[]  $vector */
    public static function pack(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /** @return float[] */
    public static function unpack(string $blob): array
    {
        return array_values(unpack('f*', $blob) ?: []);
    }
}
