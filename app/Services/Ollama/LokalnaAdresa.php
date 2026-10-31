<?php

namespace App\Services\Ollama;

use App\Services\RazresivacImena;

// Adresa je lokalna kad se SVA njena imena razrešavaju na petlju ili privatnu mrežu; ime koje se ne razrešava nije lokalno (paket 95, PITANJA-92 3).
// „Link-local" (169.254…, fe80::) nije na spisku: paket traži petlju i privatnu mrežu.
final class LokalnaAdresa
{
    public function __construct(private readonly RazresivacImena $razresivac) {}

    public function jeLokalna(string $adresa): bool
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
            if (! self::jePetljaIliPrivatna($ip)) {
                return false;
            }
        }

        return $adrese !== [];
    }

    private static function jePetljaIliPrivatna(string $ip): bool
    {
        $bajtovi = inet_pton($ip);

        if ($bajtovi === false) {
            return false;
        }

        // IPv4 zapisan kao IPv6 (::ffff:127.0.0.1) važi kao IPv4.
        if (strlen($bajtovi) === 16 && str_starts_with($bajtovi, str_repeat("\0", 10)."\xff\xff")) {
            $bajtovi = substr($bajtovi, 12);
        }

        if (strlen($bajtovi) === 4) {
            return ord($bajtovi[0]) === 127 || filter_var((string) inet_ntop($bajtovi), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false;
        }

        return $bajtovi === str_repeat("\0", 15)."\x01" || (ord($bajtovi[0]) & 0xFE) === 0xFC;
    }
}
