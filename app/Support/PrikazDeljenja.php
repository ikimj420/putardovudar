<?php

namespace App\Support;

// „Pošalji komšiji": obične veze ka Viberu i WhatsAppu sa naslovom i adresom strane, na jednom mestu. Bez skripte i bez praćenja.
// Tekstovi su predlog; Ivan odobrava (PITANJA-46-52.md, paket 47).
final class PrikazDeljenja
{
    public const NASLOV = 'Pošalji komšiji';

    public const VIBER = 'Viber';

    public const WHATSAPP = 'WhatsApp';

    public static function viber(string $naslov, string $adresa): string
    {
        return 'viber://forward?text='.self::tekst($naslov, $adresa);
    }

    public static function whatsapp(string $naslov, string $adresa): string
    {
        return 'https://wa.me/?text='.self::tekst($naslov, $adresa);
    }

    // Naslov i adresa jedno ispod drugog; kodiranje po RFC 3986 (razmak je %20, ne +), pa ništa u naslovu ne lomi vezu.
    private static function tekst(string $naslov, string $adresa): string
    {
        return rawurlencode($naslov."\n".$adresa);
    }
}
