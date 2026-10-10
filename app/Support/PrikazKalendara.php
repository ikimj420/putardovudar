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

    // „Prošli put: do 26.01.2026." ili, kad se zna i početak, „Prošli put: od 01.12.2025. do 26.01.2026.".
    public static function prosliPut(KalendarStavka $stavka): string
    {
        $od = $stavka->otvoreno_od === null ? '' : 'od '.Prikaz::datum($stavka->otvoreno_od).' ';

        return 'Prošli put: '.$od.'do '.Prikaz::datum($stavka->otvoreno_do);
    }

    // Adrese se porede bez razmaka i bez kose crte na kraju, da isti izvor ne promakne zbog sitnice.
    public static function istiLink(?string $prvi, ?string $drugi): bool
    {
        $prvi = rtrim(trim((string) $prvi), '/');

        return $prvi !== '' && $prvi === rtrim(trim((string) $drugi), '/');
    }
}
