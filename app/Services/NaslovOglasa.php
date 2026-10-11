<?php

namespace App\Services;

use App\Support\PoredjenjeTeksta;

// Jedino mesto koje kaže koje reči u naslovu znače da tekst nije oglas nego rezultat ili spisak (paket 63). Oglas za posao se objavljuje bez mišljenja
// modela, pa ovo proverava kod. Reči su stabla bez dijakritika; predlog proširenja spiska je u PITANJA-63.md.
final class NaslovOglasa
{
    private const RECI = ['rezultat\w*', 'list(?:a|e|u|i|om|ama)', 'izabran\w*', 'dobitni(?:k|c)\w*'];

    public static function jeRezultatIliSpisak(string $naslov): bool
    {
        return preg_match('/\b(?:'.implode('|', self::RECI).')\b/', PoredjenjeTeksta::normalizuj($naslov)) === 1;
    }
}
