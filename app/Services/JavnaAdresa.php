<?php

namespace App\Services;

// Adresa je javna samo ako je http ili https i ako se SVA njena imena razrešavaju na javne adrese; petlja, privatna i „link-local" mreža nisu javne (paket 95, PITANJA-92 1).
// Ime koje se ne razrešava nije javno: bez adrese nema šta da se proveri.
final class JavnaAdresa
{
    public function __construct(private readonly RazresivacImena $razresivac) {}

    public function jeJavna(string $adresa): bool
    {
        $delovi = parse_url($adresa);

        if (! is_array($delovi) || ! in_array(strtolower($delovi['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $ime = trim($delovi['host'] ?? '', '[]');

        if ($ime === '') {
            return false;
        }

        $adrese = filter_var($ime, FILTER_VALIDATE_IP) !== false ? [$ime] : $this->razresivac->adrese($ime);

        foreach ($adrese as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return false;
            }
        }

        return $adrese !== [];
    }
}
