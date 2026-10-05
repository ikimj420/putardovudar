<?php

namespace App\Support;

use Carbon\CarbonInterface;

// Jedino mesto gde se datum i decimalni broj pretvaraju u tekst, da isti broj ne bude napisan na dva načina.
final class Prikaz
{
    public const DATUM = 'd.m.Y.';

    public static function datum(CarbonInterface $datum): string
    {
        return $datum->format(self::DATUM);
    }

    public static function broj(int|float $broj, int $decimale): string
    {
        return number_format($broj, $decimale, ',', '');
    }
}
