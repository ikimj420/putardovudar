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

    // Oblik reči uz broj: jedan i dvadeset jedan (jednina), dva do četiri osim dvanaest do četrnaest (dva oblika), ostalo (množina).
    /** @param  array{0: string, 1: string, 2: string}  $oblici  uz 1, uz 2, uz 5 */
    public static function oblik(int $broj, array $oblici): string
    {
        $posleDesetice = $broj % 10;
        $posleStotine = $broj % 100;

        return match (true) {
            $posleDesetice === 1 && $posleStotine !== 11 => $oblici[0],
            $posleDesetice >= 2 && $posleDesetice <= 4 && ($posleStotine < 12 || $posleStotine > 14) => $oblici[1],
            default => $oblici[2],
        };
    }

    public static function broj(int|float $broj, int $decimale): string
    {
        return number_format($broj, $decimale, ',', '');
    }
}
