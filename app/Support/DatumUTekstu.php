<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

// Proverava da li datum doslovno piše u tekstu, u nekom od uobičajenih oblika. Godina mora da piše.
final class DatumUTekstu
{
    // Nominativ, genitiv („do 20. oktobra") i instrumental („zaključno sa 20. oktobrom"); u instrumentalu septembar, oktobar, novembar i decembar gube „a" (paket 72), a maj je „majem" (paket 73; „majom" ostaje).
    public const MESECI = [
        1 => 'januar|januara|januarom', 2 => 'februar|februara|februarom', 3 => 'mart|marta|martom', 4 => 'april|aprila|aprilom', 5 => 'maj|maja|majem|majom', 6 => 'jun|juna|junom',
        7 => 'jul|jula|julom', 8 => 'avgust|avgusta|avgustom', 9 => 'septembar|septembra|septembrom', 10 => 'oktobar|oktobra|oktobrom', 11 => 'novembar|novembra|novembrom', 12 => 'decembar|decembra|decembrom',
    ];

    // Prvi dan sa datim danom i mesecom koji nije pre $posle (isti dan važi); za 29. februar to može da bude i nekoliko godina kasnije.
    public static function prviPosle(int $dan, int $mesec, CarbonInterface $posle): ?CarbonImmutable
    {
        $od = CarbonImmutable::parse($posle->toDateString());

        for ($godina = $od->year; $godina <= $od->year + 8; $godina++) {
            if (checkdate($mesec, $dan, $godina) && ! CarbonImmutable::create($godina, $mesec, $dan)->startOfDay()->lessThan($od)) {
                return CarbonImmutable::create($godina, $mesec, $dan)->startOfDay();
            }
        }

        return null;
    }

    // Gde u tekstu (bajt) prvi put piše dan i mesec, brojem („20.10.") ili imenom meseca („20. oktobra"), sa godinom ili bez nje; null kad ne piše.
    public static function prviPolozaj(string $tekst, int $dan, int $mesec): ?int
    {
        $uzorci = [sprintf('(?<!\d)0?%d\s*[./-]\s*0?%d(?!\d)', $dan, $mesec), sprintf('(?<!\d)0?%d\s*\.?\s*(?:%s)(?!\p{L})', $dan, self::MESECI[$mesec])];
        $polozaji = [];

        foreach ($uzorci as $uzorak) {
            if (preg_match('~'.$uzorak.'~iu', $tekst, $nalaz, PREG_OFFSET_CAPTURE) === 1) {
                $polozaji[] = $nalaz[0][1];
            }
        }

        return $polozaji === [] ? null : min($polozaji);
    }

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
