<?php

namespace App\Services;

// Adresa je javna samo ako je http ili https i ako se SVA njena imena razrešavaju na javne adrese; petlja, privatna i „link-local" mreža nisu javne (paket 95, PITANJA-92 1).
// Ime koje se ne razrešava ni na šta nije nejavno nego neproverljivo: ocena je tada NeRazresava, a jeJavna je za to false jer se takva adresa ne preuzima (paket 99).
final class JavnaAdresa
{
    public function __construct(private readonly RazresivacImena $razresivac) {}

    public function jeJavna(string $adresa): bool
    {
        return $this->ocena($adresa) === OcenaAdrese::Javna;
    }

    public function ocena(string $adresa): OcenaAdrese
    {
        $delovi = parse_url($adresa);

        if (! is_array($delovi) || ! in_array(strtolower($delovi['scheme'] ?? ''), ['http', 'https'], true)) {
            return OcenaAdrese::Nejavna;
        }

        $ime = trim($delovi['host'] ?? '', '[]');

        if ($ime === '') {
            return OcenaAdrese::Nejavna;
        }

        $adrese = filter_var($ime, FILTER_VALIDATE_IP) !== false ? [$ime] : $this->razresivac->adrese($ime);

        if ($adrese === []) {
            return OcenaAdrese::NeRazresava;
        }

        foreach ($adrese as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return OcenaAdrese::Nejavna;
            }
        }

        return OcenaAdrese::Javna;
    }
}
