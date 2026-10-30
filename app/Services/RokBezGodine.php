<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

// Rok bez godine koji pada dalje od ovoliko meseci posle datuma objave ostaje nacrt (paket 84): „5. decembra" u vesti od 8. decembra 2025. nije 5. decembar 2026.
// Jedino mesto te granice: obrada nacrta i dnevna provera javnih prilika (paket 85) pitaju ovde.
final class RokBezGodine
{
    // Razlog nacrta; isti tekst piše obrada i provera javnih prilika, pa ih izbor „Zašto je nacrt" u adminu svrstava zajedno.
    public const RAZLOG = 'rok bez godine je daleko od datuma objave';

    private const NAJVISE_MESECI = 6;

    public static function jeDalekoOdObjave(CarbonInterface $rok, CarbonInterface $objavljeno): bool
    {
        return CarbonImmutable::parse($rok->toDateString())->greaterThan(CarbonImmutable::parse($objavljeno->toDateString())->addMonthsNoOverflow(self::NAJVISE_MESECI));
    }
}
