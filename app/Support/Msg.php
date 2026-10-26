<?php

namespace App\Support;

/**
 * Marks a UI string that is translated later, in someone else's language (notification
 * titles are rendered per recipient). Returns the key unchanged; the translation test
 * picks up Msg::key('…') like __('…').
 */
final class Msg
{
    public static function key(string $key): string
    {
        return $key;
    }
}
