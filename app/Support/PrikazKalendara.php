<?php

namespace App\Support;

use App\Models\KalendarStavka;

// Tekstovi kalendara koje vide posetilac i Ivan, na jednom mestu (kao PrikazPrilike). Predlog je u PITANJA-39-45.md, paket 44.
final class PrikazKalendara
{
    public const NASLOV = 'Kalendar';

    public const UVOD = 'Ovde piše samo šta je stvarno bilo otvoreno prošli put; ništa nije najava.';

    public const NEMA_STAVKI = 'Kalendar je za sada prazan.';

    public const SADA_OTVORENO = 'Sada otvoreno: ';

    // Oznaka kartice iz kalendara uz odgovor pomoćnika.
    public const OZNAKA = 'Kalendar';

    // Admin: dugme na kartici prilike kojoj je prošao rok, i poruke posle klika.
    public const DODAJ = 'Dodaj u kalendar';

    public const DODATO = 'Stavka je dodata u kalendar kao nacrt.';

    public const VEC_JE = 'Ova prilika je već u kalendaru.';

    // Admin, polje uz stavku (paket 89); tekstovi su predlog (PITANJA-89.md), Ivan odobrava.
    public const SADA_OTVORENO_KAO = 'Sada otvoreno kao';

    public const SADA_OTVORENO_KAO_OPIS = 'Izaberi priliku sa sajta koja je ista kao ova stavka. Kalendar tada piše „Sada otvoreno“ dok je prilika na sajtu.';

    // „Prošli put: do 26.01.2026." ili, kad se zna i početak, „Prošli put: od 01.12.2025. do 26.01.2026.".
    public static function prosliPut(KalendarStavka $stavka): string
    {
        $od = $stavka->otvoreno_od === null ? '' : 'od '.Prikaz::datum($stavka->otvoreno_od).' ';

        return 'Prošli put: '.$od.'do '.Prikaz::datum($stavka->otvoreno_do);
    }

    // Ista adresa je ista strana: razlika u http/https, „www.", velikim slovima sajta, podrazumevanom portu, kosi crti na kraju i delu iza „#" se ne računa, a putanja i upit se računaju
    // (strana sa drugim upitom je druga strana, a „novosti.sajt.rs" nije „sajt.rs"). Ono što nije adresa poredi se kao tekst, bez razmaka i kose crte na kraju.
    public static function istiLink(?string $prvi, ?string $drugi): bool
    {
        $prvi = self::kljucLinka((string) $prvi);

        return $prvi !== '' && $prvi === self::kljucLinka((string) $drugi);
    }

    private static function kljucLinka(string $link): string
    {
        $link = rtrim(trim($link), '/');
        $delovi = parse_url($link);

        if (! is_array($delovi) || ! is_string($delovi['host'] ?? null) || $delovi['host'] === '') {
            return $link;
        }

        $port = isset($delovi['port']) && ! in_array($delovi['port'], [80, 443], true) ? ':'.$delovi['port'] : '';

        return (string) preg_replace('/^www\./', '', mb_strtolower($delovi['host'])).$port.rtrim($delovi['path'] ?? '', '/').(isset($delovi['query']) ? '?'.$delovi['query'] : '');
    }
}
