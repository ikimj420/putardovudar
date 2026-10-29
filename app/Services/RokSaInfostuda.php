<?php

namespace App\Services;

// Oglasi sa poslovi.infostud.com nemaju reč „rok": datum isteka stoji uz ikonu sata, a u kodu strane je polje expirationDate (paket 63). Ovo je jedino mesto
// koje čita taj izvor i čita samo to polje. Datum se prima samo ako isti datum piše i u tekstu strane, jer je tada citat roka doslovan.
final class RokSaInfostuda
{
    private const SERVER = 'poslovi.infostud.com';

    // Jedino mesto koje kaže da je adresa oglas sa Infostuda; koristi ga i čitanje strane (oglasi na engleskom dokazuju „posao" okvirom sajta, paket 76).
    public static function jeInfostud(string $adresa): bool
    {
        return parse_url($adresa, PHP_URL_HOST) === self::SERVER;
    }

    /** @return array{rok: string, citat: string}|null rok kao Y-m-d i datum kako piše u tekstu strane (d.m.Y.) */
    public function izStrane(string $adresa, string $html, string $tekst): ?array
    {
        if (! self::jeInfostud($adresa)) {
            return null;
        }

        preg_match_all('/"expirationDate":"([^"]*)"/', $html, $nalazi);
        $datumi = array_values(array_unique($nalazi[1]));

        // Strana ima jedno polje; više različitih datuma znači da nije jasno koji je rok ovog oglasa. Oblik je dan.mesec.godina. i datum mora da postoji.
        if (count($datumi) !== 1 || preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\.$/', $datumi[0], $deo) !== 1 || ! checkdate((int) $deo[2], (int) $deo[1], (int) $deo[3])) {
            return null;
        }

        if (preg_match('/(?<![\d.])'.preg_quote($datumi[0], '/').'(?![\p{L}\d])/u', $tekst) !== 1) {
            return null;
        }

        return ['rok' => $deo[3].'-'.$deo[2].'-'.$deo[1], 'citat' => $datumi[0]];
    }
}
