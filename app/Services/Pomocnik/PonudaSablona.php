<?php

namespace App\Services\Pomocnik;

// Šablon koji pomoćnik nudi uz odgovor: naslov i strana sa pitanjima šablona.
final readonly class PonudaSablona
{
    public function __construct(public string $naslov, public string $adresa) {}
}
