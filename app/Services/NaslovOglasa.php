<?php

namespace App\Services;

use App\Support\PoredjenjeTeksta;

// Jedino mesto koje kaže koje reči u naslovu znače da tekst nije oglas nego rezultat ili spisak (paket 63, dopuna u paketu 67). Oglas za posao se objavljuje bez mišljenja
// modela, pa ovo proverava kod. Reči su stabla bez dijakritika (đ postaje „dj", a i bez njega se čita: „nagraden").
final class NaslovOglasa
{
    private const RECI = ['rezultat\w*', 'list(?:a|e|u|i|om|ama)', 'spis(?:ak|k)\w*', 'dobitni(?:k|c)\w*', 'pobedni(?:k|c)\w*', 'nagrad(?:j)?en\w*', 'odabran\w*'];

    // „Izabran" je i naziv radnog mesta („Izabrani lekar"), pa odbija samo kad u naslovu stoji i „kandidat…".
    private const IZABRAN = 'izabran\w*';

    private const KANDIDAT = 'kandidat\w*';

    public static function jeRezultatIliSpisak(string $naslov): bool
    {
        $tekst = PoredjenjeTeksta::normalizuj($naslov);

        return preg_match('/\b(?:'.implode('|', self::RECI).')\b/', $tekst) === 1
            || (preg_match('/\b'.self::IZABRAN.'\b/', $tekst) === 1 && preg_match('/\b'.self::KANDIDAT.'\b/', $tekst) === 1);
    }
}
