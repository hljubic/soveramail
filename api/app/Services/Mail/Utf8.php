<?php

namespace App\Services\Mail;

/**
 * Text from the wire that is not valid UTF-8 (raw 8-bit headers or bodies from
 * old mailers with no or a wrong charset label).
 */
final class Utf8
{
    /**
     * Read invalid UTF-8 as Windows-1250, the usual legacy charset of this app's
     * users (č ć đ š ž), which also keeps the common Western letters. Valid UTF-8
     * is returned untouched. Never returns invalid UTF-8.
     */
    public static function fix(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        // iconv: mbstring does not know Windows-1250.
        $converted = function_exists('iconv') ? @iconv('WINDOWS-1250', 'UTF-8//IGNORE', $value) : false;

        return is_string($converted) && mb_check_encoding($converted, 'UTF-8') ? $converted : mb_scrub($value, 'UTF-8');
    }
}
