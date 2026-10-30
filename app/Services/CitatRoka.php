<?php

namespace App\Services;

use App\Support\DatumiUTekstu;
use App\Support\DatumUTekstu;
use App\Support\PoredjenjeTeksta;
use Carbon\CarbonImmutable;

// Jedino mesto koje traži rečenicu u kojoj piše dati rok (obrada nacrta i dnevna provera javnih prilika): bez rečenice sa rečju roka datum nije rok.
final class CitatRoka
{
    // Koliko reči ispred datuma se gleda za reč roka (paket 70).
    private const NAJVISE_RECI_PRE_DATUMA = 12;

    // Najduža rečenica koju kod uzima kao citat roka celu; duža se seče oko datuma.
    private const NAJVISE_ZNAKOVA_CITATA_ROKA = 300;

    // Reč roka mora da stoji najviše 12 reči ispred datuma, i posle prethodnog datuma u rečenici (osim kad ih spaja samo „do" ili crtica, kao u „od 28. septembra do 12.
    // oktobra"): „Rok za prijavu je 11. oktobra, a radionica je 17. oktobra." dokazuje samo 11. oktobar (paket 70). Prva rečenica sa „rok…" ima prednost; inače prva sa „prijav…",
    // ili sa „do" uz „otvoren…", „konkurs…" ili „poziv…". Delovi se dele samo pre velikog slova, da „28. septembra" i „12. 10. 2026." ostanu u jednoj rečenici.
    public static function recenicaSaRokom(CarbonImmutable $rok, string $tekst): ?string
    {
        $prva = null;

        foreach (preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', $tekst) ?: [] as $deo) {
            $deo = trim($deo);
            $mala = mb_strtolower($deo);
            $pojave = DatumiUTekstu::pojave($deo);

            foreach ($pojave as $i => $pojava) {
                if ($pojava['dan'] !== $rok->day || $pojava['mesec'] !== $rok->month || ($pojava['godina'] !== null && $pojava['godina'] !== $rok->year)) {
                    continue;
                }

                $pocetak = 0;

                for ($j = $i - 1; $j >= 0; $j--) {
                    $izmedju = substr($mala, $pojave[$j]['do'], $pojave[$j + 1]['od'] - $pojave[$j]['do']);

                    if (preg_match('/^[\s.,]*(?:do|-|–|—)[\s.,]*$/u', $izmedju) !== 1) {
                        $pocetak = $pojave[$j]['do'];

                        break;
                    }
                }

                $reci = preg_split('/\s+/u', trim(substr($mala, $pocetak, $pojava['od'] - $pocetak)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $prozor = PoredjenjeTeksta::normalizuj(implode(' ', array_slice($reci, -self::NAJVISE_RECI_PRE_DATUMA)));

                if (preg_match('/\brok\w*/', $prozor) === 1) {
                    return self::okoDatuma($deo, $rok);
                }

                if ($prva === null && (preg_match('/\bprijav\w*/', $prozor) === 1 || (preg_match('/\bdo\b/', $prozor) === 1 && preg_match('/\b(?:otvoren|konkurs|poziv)\w*/', $prozor) === 1))) {
                    $prva = $deo;
                }
            }
        }

        return $prva === null ? null : self::okoDatuma($prva, $rok);
    }

    // Duga rečenica se seče oko datuma na cele reči (najviše 12 pre i 14 posle), da citat ostane kratak i doslovan.
    private static function okoDatuma(string $recenica, CarbonImmutable $rok): string
    {
        $polozaj = mb_strlen($recenica) > self::NAJVISE_ZNAKOVA_CITATA_ROKA ? DatumUTekstu::prviPolozaj($recenica, $rok->day, $rok->month) : null;

        if ($polozaj === null) {
            return $recenica;
        }

        $pre = preg_split('/\s+/u', trim(substr($recenica, 0, $polozaj))) ?: [];
        $posle = preg_split('/\s+/u', trim(substr($recenica, $polozaj))) ?: [];

        return implode(' ', [...array_slice($pre, -12), ...array_slice($posle, 0, 14)]);
    }
}
