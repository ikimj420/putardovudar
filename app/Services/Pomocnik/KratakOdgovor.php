<?php

namespace App\Services\Pomocnik;

// Odgovor modela se skraćuje na najviše dve rečenice (paket 65/T2): model uputstvo o dužini ne drži (izmereno na pravom modelu, nabraja prilike
// i prepričava rokove), a detalji su u karticama izvora. Ništa se ne dodaje, samo odseca; proveru radi kod posle skraćivanja.
final class KratakOdgovor
{
    public const NAJVISE_RECENICA = 2;

    public static function skrati(string $tekst): string
    {
        // Nabrajanje (crtice, tačke, brojevi): ostaje samo uvod pre prve stavke.
        $uvod = (string) preg_split('/\R\s*(?:[-•*]|\d+[.)])\s+/u', trim($tekst), 2)[0];

        // Rečenica se završava tačkom, upitnikom ili uzvičnikom iza kojih sledi veliko slovo; „15. novembra" i „2026. do" ne dele rečenicu.
        $recenice = preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', (string) preg_replace('/\s*\R\s*/u', ' ', $uvod), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Uvod koji je stajao ispred nabrajanja („Našao sam tri konkursa:") posle odsecanja je obična rečenica.
        return (string) preg_replace('/[:;,]$/u', '.', implode(' ', array_slice($recenice, 0, self::NAJVISE_RECENICA)));
    }
}
