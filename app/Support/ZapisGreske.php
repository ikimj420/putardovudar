<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

// Neočekivana greška pri razgovoru sa modelom ide u log samo kao vrsta (ime klase): poruka i trag poziva mogu da nose ono što je čovek upisao ili što je model odgovorio.
final class ZapisGreske
{
    public static function vrsta(string $gde, Throwable $izuzetak): void
    {
        Log::warning($gde.': neočekivana greška, '.$izuzetak::class);
    }
}
