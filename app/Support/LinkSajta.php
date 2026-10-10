<?php

namespace App\Support;

// Jedino mesto koje kaže šta je ispravan link sajta organizacije: veb adresa sa http ili https.
final class LinkSajta
{
    public static function jeIspravan(?string $vrednost): bool
    {
        $link = trim((string) $vrednost);

        return $link !== ''
            && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
