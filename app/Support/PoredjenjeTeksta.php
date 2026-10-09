<?php

namespace App\Support;

use Illuminate\Support\Str;

// Poređenje reči iz pitanja sa tekstom prilike: bez razlike u slovima, dijakriticima i pismu (ćirilica ide kroz Latinica).
final class PoredjenjeTeksta
{
    public static function normalizuj(string $tekst): string
    {
        $tekst = Str::ascii(mb_strtolower(Latinica::izCirilice($tekst)));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $tekst) ?? '');
    }

    // Srpski padeži menjaju kraj reči („skladištu" prema „skladište", „Čačku" prema „Čačak"), pa se poredi početak reči.
    public static function stablo(string $rec): string
    {
        $duzina = strlen($rec);

        return match (true) {
            $duzina >= 7 => substr($rec, 0, $duzina - 3),
            $duzina >= 5 => substr($rec, 0, $duzina - 2),
            $duzina === 4 => substr($rec, 0, 3),
            default => $rec,
        };
    }

    // Dve reči su ista reč u drugom padežu kad jedno stablo počinje drugim.
    public static function istaRec(string $prva, string $druga): bool
    {
        $a = self::stablo($prva);
        $b = self::stablo($druga);

        return $a !== '' && $b !== '' && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    // Svaka reč izraza mora da počinje na neku reč u tekstu; oba teksta su već normalizovana.
    public static function sadrziIzraz(string $normalizovanTekst, string $normalizovanIzraz): bool
    {
        $reci = array_filter(explode(' ', $normalizovanIzraz), fn (string $rec) => $rec !== '');

        if ($reci === []) {
            return false;
        }

        foreach ($reci as $rec) {
            if (preg_match('/\b'.preg_quote(self::stablo($rec), '/').'/', $normalizovanTekst) !== 1) {
                return false;
            }
        }

        return true;
    }
}
