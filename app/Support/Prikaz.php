<?php

namespace App\Support;

use Carbon\CarbonInterface;

// Jedino mesto gde se datum i decimalni broj pretvaraju u tekst, da isti broj ne bude napisan na dva načina.
final class Prikaz
{
    public const DATUM = 'd.m.Y.';

    // Nazivi meseci u nominativu, za naslove odeljaka (kalendar); redni broj meseca je ključ.
    private const MESECI = [
        1 => 'Januar', 2 => 'Februar', 3 => 'Mart', 4 => 'April', 5 => 'Maj', 6 => 'Jun',
        7 => 'Jul', 8 => 'Avgust', 9 => 'Septembar', 10 => 'Oktobar', 11 => 'Novembar', 12 => 'Decembar',
    ];

    public static function datum(CarbonInterface $datum): string
    {
        return $datum->format(self::DATUM);
    }

    public static function mesec(int $broj): string
    {
        return self::MESECI[$broj] ?? '';
    }

    public static function broj(int|float $broj, int $decimale): string
    {
        return number_format($broj, $decimale, ',', '');
    }
}
