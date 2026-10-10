<?php

namespace App\Support;

use App\Models\Prilika;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

// Rok prilike kao mali kalendar fajl (.ics, RFC 5545): događaj na ceo dan sa naslovom i adresom naše strane. Ništa se ne čuva.
final class RokUKalendaru
{
    private const NAJVISE_BAJTOVA_U_REDU = 75;

    public static function sadrzaj(Prilika $prilika, string $adresa, CarbonInterface $sada): string
    {
        $rok = CarbonImmutable::parse($prilika->rok?->toDateString() ?? '');
        $naslov = PrikazPrilike::ROK_U_KALENDARU.$prilika->naslov;

        $redovi = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Putardo Vudar//Rok prilike//SR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:prilika-'.$prilika->getKey().'-'.$rok->format('Ymd').'@'.(parse_url($adresa, PHP_URL_HOST) ?: 'putardovudar'),
            'DTSTAMP:'.$sada->toImmutable()->utc()->format('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:'.$rok->format('Ymd'),
            'DTEND;VALUE=DATE:'.$rok->addDay()->format('Ymd'),
            'SUMMARY:'.self::tekst($naslov),
            'DESCRIPTION:'.self::tekst($prilika->naslov."\n".$adresa),
            'URL:'.$adresa,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::savij(...), $redovi))."\r\n";
    }

    // Vrednost teksta po RFC 5545: obrnuta kosa crta, tačka-zarez, zarez i novi red se pišu sa kosom crtom.
    private static function tekst(string $vrednost): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', '\\n'], $vrednost);
    }

    // Red duži od 75 bajtova se prelama tako što sledeći red počinje razmakom; znak sa dva bajta se nikad ne seče.
    private static function savij(string $red): string
    {
        $izlaz = '';
        $trenutni = 0;

        foreach (mb_str_split($red) as $znak) {
            $bajtova = strlen($znak);

            if ($trenutni + $bajtova > self::NAJVISE_BAJTOVA_U_REDU) {
                $izlaz .= "\r\n ";
                $trenutni = 1;
            }

            $izlaz .= $znak;
            $trenutni += $bajtova;
        }

        return $izlaz;
    }
}
