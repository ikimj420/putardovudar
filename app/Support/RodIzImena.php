<?php

namespace App\Support;

use App\Enums\Rod;

// Rod iz imena za „Napiši moj" (paket 64): prvo ime, pa spisak izuzetaka, pa kraj imena. Šta ne prepozna ili može biti oba, vraća null, pa pomoćnik pita.
// Spisci su predlog (PITANJA-64.md): Ivan ih dopunjuje na ovom jednom mestu.
final class RodIzImena
{
    // Ključ polja šablona sa imenom i prezimenom (prvo pitanje svakog šablona).
    public const POLJE = 'ime';

    // Ime može biti i muško i žensko: pita se.
    public const OBA = ['Saša', 'Vanja', 'Andrea', 'Dobrica'];

    // Muška imena na „a", koja bi po kraju bila ženska.
    public const MUSKA_NA_A = ['Nikola', 'Luka', 'Jovica', 'Bora', 'Mika', 'Žika', 'Pera', 'Sava', 'Kosta', 'Andrija', 'Ilija', 'Matija', 'Tadija', 'Sima', 'Boža', 'Rista', 'Mita', 'Miša', 'Aleksa', 'Vasa', 'Nemanja', 'Nebojša', 'Siniša', 'Dragiša', 'Mališa', 'Boriša'];

    // Muška imena na „e".
    public const MUSKA_NA_E = ['Đorđe', 'Vasilije', 'Mile', 'Rade'];

    // Ženska imena koja ne završavaju na „a".
    public const ZENSKA_BEZ_A = ['Ines', 'Nives', 'Karmen', 'Ester', 'Miriam', 'Mirjam', 'Iris', 'Ingrid', 'Margit'];

    public static function odredi(string $imeIPrezime): ?Rod
    {
        $ime = self::uporedivo(self::prvoIme($imeIPrezime));

        if (preg_match('/^[a-z]{2,}$/', $ime) !== 1 || in_array($ime, self::uporedivi(self::OBA), true)) {
            return null;
        }

        return match (true) {
            in_array($ime, self::uporedivi(self::MUSKA_NA_A), true), in_array($ime, self::uporedivi(self::MUSKA_NA_E), true) => Rod::Muski,
            in_array($ime, self::uporedivi(self::ZENSKA_BEZ_A), true) => Rod::Zenski,
            str_ends_with($ime, 'a') => Rod::Zenski,
            // Muško je ono što se završava na „o" ili suglasnik; „e", „i", „u" i ostalo se ne prepoznaje.
            str_ends_with($ime, 'o') => Rod::Muski,
            ! preg_match('/[aeiouy]$/', $ime) => Rod::Muski,
            default => null,
        };
    }

    // Ime stoji pre prezimena; spojeno ime („Ana-Marija") se čita po prvom delu.
    private static function prvoIme(string $imeIPrezime): string
    {
        $delovi = preg_split('/[\s-]+/u', trim(Latinica::izCirilice($imeIPrezime)), -1, PREG_SPLIT_NO_EMPTY);

        return $delovi[0] ?? '';
    }

    // Bez velikih slova i dijakritika, jer ljudi ime kucaju i bez njih („Sasa").
    private static function uporedivo(string $ime): string
    {
        return strtr(mb_strtolower($ime), ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj']);
    }

    /**
     * @param  list<string>  $imena
     * @return list<string>
     */
    private static function uporedivi(array $imena): array
    {
        return array_map(self::uporedivo(...), $imena);
    }
}
