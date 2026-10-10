<?php

namespace App\Services\Pomocnik;

// Izvor uz odgovor sklapa kod iz zapisa, nikad model: naslov, rok, naša strana i zvanični izvor. Vodič nema rok ni zvanični izvor;
// organizacija nema rok, a njen sajt je zvanični izvor.
final readonly class Izvor
{
    public function __construct(
        public string $naslov,
        public ?string $rok,
        public string $adresa,
        public ?string $zvanicniNaziv,
        public ?string $zvanicniLink,
        public bool $jeVodic = false,
        public bool $jeOrganizacija = false,
    ) {}
}
