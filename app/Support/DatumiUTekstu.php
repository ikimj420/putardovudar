<?php

namespace App\Support;

// Pronalazi datume u slobodnom tekstu (odgovor modela), da kod može da ih uporedi sa onim što piše u zapisima.
final class DatumiUTekstu
{
    /** @return list<array{dan: int, mesec: int, godina: int|null}> */
    public static function izvuci(string $tekst): array
    {
        $tekst = mb_strtolower($tekst);
        $nadjeno = [];

        preg_match_all('/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', $tekst, $iso, PREG_SET_ORDER);

        foreach ($iso as $deo) {
            $nadjeno[] = [(int) $deo[3], (int) $deo[2], (int) $deo[1]];
        }

        preg_match_all('~(?<!\d)(\d{1,2})\s*[./-]\s*(\d{1,2})\s*[./-]\s*(\d{4})(?!\d)~', $tekst, $brojevi, PREG_SET_ORDER);

        foreach ($brojevi as $deo) {
            $nadjeno[] = [(int) $deo[1], (int) $deo[2], (int) $deo[3]];
        }

        $nazivi = self::nazivi();
        $uzorak = implode('|', array_map('preg_quote', array_keys($nazivi)));

        preg_match_all('/(?<!\d)(\d{1,2})\s*\.?\s*('.$uzorak.')(?!\p{L})(?:\s*,?\s*(\d{4})(?!\d))?/u', $tekst, $recima, PREG_SET_ORDER);

        foreach ($recima as $deo) {
            $nadjeno[] = [(int) $deo[1], $nazivi[$deo[2]], isset($deo[3]) && $deo[3] !== '' ? (int) $deo[3] : null];
        }

        // „20.10." bez godine; „10.5" bez završne tačke je broj, ne datum.
        preg_match_all('/(?<![\d.])(\d{1,2})\.\s?(\d{1,2})\.(?!\d)(?!\s*\d{4})/', $tekst, $bezGodine, PREG_SET_ORDER);

        foreach ($bezGodine as $deo) {
            $nadjeno[] = [(int) $deo[1], (int) $deo[2], null];
        }

        $datumi = [];

        foreach ($nadjeno as [$dan, $mesec, $godina]) {
            if ($mesec >= 1 && $mesec <= 12 && $dan >= 1 && $dan <= 31) {
                $datumi[$dan.'-'.$mesec.'-'.$godina] = ['dan' => $dan, 'mesec' => $mesec, 'godina' => $godina];
            }
        }

        return array_values($datumi);
    }

    /** @return array<string, int> mesec napisan rečima (nominativ i genitiv) → broj meseca */
    private static function nazivi(): array
    {
        $nazivi = [];

        foreach (DatumUTekstu::MESECI as $broj => $oblici) {
            foreach (explode('|', $oblici) as $oblik) {
                $nazivi[$oblik] = $broj;
            }
        }

        uksort($nazivi, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $nazivi;
    }
}
