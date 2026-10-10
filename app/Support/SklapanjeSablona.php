<?php

namespace App\Support;

use App\Models\Sablon;

// Šablon + ono što je čovek upisao = tekst za štampu ili kopiranje. Radi samo u memoriji: ništa se ne čuva.
final class SklapanjeSablona
{
    // Prazno polje ne ostavlja „{ime}" u tekstu, nego liniju koju čovek posle štampe popuni olovkom.
    public const PRAZNO = '__________';

    public const NAJVISE_ZNAKOVA_POLJA = 2000;

    /** @return list<string> ključevi koji u tekstu stoje kao {kljuc}, redom, bez ponavljanja */
    public static function kljuceUTekstu(string $tekst): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $tekst, $nalazi);

        return array_values(array_unique($nalazi[1]));
    }

    /** @param  array<string, mixed>  $unosi  ključ polja → šta je čovek upisao */
    public static function sklopi(Sablon $sablon, array $unosi): string
    {
        $vrednosti = [];

        foreach ($sablon->kljucevi() as $kljuc) {
            $vrednosti[$kljuc] = self::ocisti($unosi[$kljuc] ?? null);
        }

        // Jedan prolaz kroz tekst šablona: upisano se ne razvija ponovo, pa „{ime}" u polju ostaje kako je upisano.
        $tekst = preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn (array $mesto) => ($vrednosti[$mesto[1]] ?? '') !== '' ? $vrednosti[$mesto[1]] : self::PRAZNO, (string) $sablon->tekst);

        return str_replace(["\r\n", "\r"], "\n", (string) $tekst);
    }

    private static function ocisti(mixed $vrednost): string
    {
        if (! is_string($vrednost)) {
            return '';
        }

        // Novi red ostaje (polje sa više redova), ostali upravljački znaci ispadaju.
        $vrednost = str_replace(["\r\n", "\r"], "\n", $vrednost);
        $vrednost = (string) preg_replace('/[^\P{C}\n]/u', '', $vrednost);

        return trim(mb_substr($vrednost, 0, self::NAJVISE_ZNAKOVA_POLJA));
    }
}
