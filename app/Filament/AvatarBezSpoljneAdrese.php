<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

// Avatar admina sklapa se ovde (inicijali na tamnoj podlozi) i ide kao „data" adresa: ime korisnika i adresa sa koje se otvara admin ne odlaze trećoj strani (paket 95, PITANJA-93 1).
// Podrazumevani izvor u Filamentu je ui-avatars.com.
final class AvatarBezSpoljneAdrese implements AvatarProvider
{
    private const NAJVISE_SLOVA = 2;

    public function get(Model $record): string
    {
        $slova = [];

        foreach (explode(' ', Filament::getNameForDefaultAvatar($record)) as $rec) {
            // Interpunkcija na početku reči („[SISTEM] Admin") ne ulazi u inicijale.
            $slovo = mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $rec), 0, 1);

            if ($slovo !== '') {
                $slova[] = mb_strtoupper($slovo);
            }
        }

        // Inicijali su samo slova i cifre (ili „?"), pa u slici nema ničeg što bi moralo da se izbegne.
        $inicijali = implode('', array_slice($slova, 0, self::NAJVISE_SLOVA)) ?: '?';
        $slika = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#18181b"/>'
            .'<text x="32" y="33" text-anchor="middle" dominant-baseline="central" font-family="sans-serif" font-size="26" fill="#ffffff">'.$inicijali.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($slika);
    }
}
