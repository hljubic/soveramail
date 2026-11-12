<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Is the Sovera Mail window open right now? The UI polls the accounts list every
 * few seconds while it is open; that request leaves a timestamp here. Mail is
 * fetched on a schedule only while the app is open, never in the background.
 */
final class AppPresence
{
    private const KEY = 'sovera:app-open-at';

    /** Without a poll for this long the app counts as closed. */
    public const OPEN_FOR_SECONDS = 180;

    public static function touch(): void
    {
        Cache::put(self::KEY, now()->getTimestamp(), now()->addSeconds(self::OPEN_FOR_SECONDS * 2));
    }

    public static function isOpen(): bool
    {
        $at = Cache::get(self::KEY);

        return is_int($at) && now()->getTimestamp() - $at <= self::OPEN_FOR_SECONDS;
    }
}
