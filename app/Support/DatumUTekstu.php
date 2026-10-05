<?php

namespace App\Support;

// Proverava da li datum doslovno piše u tekstu, u nekom od uobičajenih oblika. Godina mora da piše.
final class DatumUTekstu
{
    private const MESECI = [
        1 => 'januar|januara', 2 => 'februar|februara', 3 => 'mart|marta', 4 => 'april|aprila', 5 => 'maj|maja', 6 => 'jun|juna',
        7 => 'jul|jula', 8 => 'avgust|avgusta', 9 => 'septembar|septembra', 10 => 'oktobar|oktobra', 11 => 'novembar|novembra', 12 => 'decembar|decembra',
    ];

    // $datum je oblika 2026-10-20; tekst treba da je već latinicom (Latinica::izCirilice).
    public static function postoji(string $tekst, string $datum): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $deo) !== 1 || ! checkdate((int) $deo[2], (int) $deo[3], (int) $deo[1])) {
            return false;
        }

        [$godina, $mesec, $dan] = [(int) $deo[1], (int) $deo[2], (int) $deo[3]];
        $tekst = mb_strtolower($tekst);

        $uzorci = [
            // 2026-10-20
            sprintf('%04d-%02d-%02d', $godina, $mesec, $dan),
            // 20.10.2026, 20. 10. 2026., 20/10/2026, 20-10-2026
            sprintf('0?%d\s*[./-]\s*0?%d\s*[./-]\s*%d', $dan, $mesec, $godina),
            // 20. oktobra 2026., 20 oktobar 2026
            sprintf('0?%d\s*\.?\s*(?:%s)\s*,?\s*%d', $dan, self::MESECI[$mesec], $godina),
        ];

        foreach ($uzorci as $uzorak) {
            if (preg_match('~(?<!\d)'.$uzorak.'(?!\d)~u', $tekst) === 1) {
                return true;
            }
        }

        return false;
    }
}
