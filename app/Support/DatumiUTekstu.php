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

    /**
     * „januara 2027" i sama godina („2027"), za godine koje datum sa danom ne vidi; godina bez meseca ima mesec null.
     *
     * @return list<array{mesec: int|null, godina: int}>
     */
    public static function mesecIliGodina(string $tekst): array
    {
        $tekst = mb_strtolower($tekst);
        $nazivi = self::nazivi();
        $nadjeno = [];

        preg_match_all('/('.implode('|', array_map('preg_quote', array_keys($nazivi))).')(?!\p{L})\s*,?\s*(20\d{2})(?!\d)/u', $tekst, $recima, PREG_SET_ORDER);

        foreach ($recima as $deo) {
            $nadjeno[$nazivi[$deo[1]].'-'.$deo[2]] = ['mesec' => $nazivi[$deo[1]], 'godina' => (int) $deo[2]];
        }

        preg_match_all('/(?<!\d)(20\d{2})(?!\d)/', $tekst, $godine);

        foreach ($godine[1] as $godina) {
            $nadjeno['-'.$godina] = ['mesec' => null, 'godina' => (int) $godina];
        }

        return array_values($nadjeno);
    }

    /**
     * Isti datumi kao izvuci, ali sa mestom u tekstu (bajt od i do; tekst se čita malim slovima, pa se mesto odnosi na mb_strtolower(tekst)), redom kako stoje.
     * Uzorci se ne preklapaju: „20. 10. 2026." je jedan datum, jer uzorak bez godine ne važi kad iza njega piše godina.
     *
     * @return list<array{od: int, do: int, dan: int, mesec: int, godina: int|null}>
     */
    public static function pojave(string $tekst): array
    {
        $tekst = mb_strtolower($tekst);
        $nazivi = self::nazivi();
        $imena = implode('|', array_map('preg_quote', array_keys($nazivi)));
        $pojave = [];

        $uzorci = [
            ['/(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)/', fn (array $d) => [(int) $d[3], (int) $d[2], (int) $d[1]]],
            ['~(?<!\d)(\d{1,2})\s*[./-]\s*(\d{1,2})\s*[./-]\s*(\d{4})(?!\d)~', fn (array $d) => [(int) $d[1], (int) $d[2], (int) $d[3]]],
            ['/(?<!\d)(\d{1,2})\s*\.?\s*('.$imena.')(?!\p{L})(?:\s*,?\s*(\d{4})(?!\d))?/u', fn (array $d) => [(int) $d[1], $nazivi[$d[2]], isset($d[3]) && $d[3] !== '' ? (int) $d[3] : null]],
            ['/(?<![\d.])(\d{1,2})\.\s?(\d{1,2})\.(?!\d)(?!\s*\d{4})/', fn (array $d) => [(int) $d[1], (int) $d[2], null]],
        ];

        foreach ($uzorci as [$uzorak, $izvuci]) {
            preg_match_all($uzorak, $tekst, $nalazi, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($nalazi as $nalaz) {
                [$dan, $mesec, $godina] = $izvuci(array_map(fn (array $deo) => $deo[0], $nalaz));

                if ($mesec >= 1 && $mesec <= 12 && $dan >= 1 && $dan <= 31) {
                    $pojave[] = ['od' => $nalaz[0][1], 'do' => $nalaz[0][1] + strlen($nalaz[0][0]), 'dan' => $dan, 'mesec' => $mesec, 'godina' => $godina];
                }
            }
        }

        usort($pojave, fn (array $a, array $b) => $a['od'] <=> $b['od']);

        return $pojave;
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
