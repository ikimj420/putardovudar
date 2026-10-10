<?php

namespace App\Services\Pomocnik;

use App\Support\PoredjenjeTeksta;

// Pitanja o prevari i lažiranju ne idu ni do modela ni do pretrage; spisak je iz starog projekta (VudarAssistantService::isUnsafe).
final class OpasnaPitanja
{
    // „zaobiđem" i „zaobidjem" se razlikuju i posle normalizacije (đ postaje d), pa su oba u spisku.
    private const IZRAZI = [
        'laziram dokument',
        'lazna dokumenta',
        'lazni dokument',
        'lazna prijava',
        'fake documents',
        'falsifikujem',
        'falsifik',
        'falsifikat',
        'prevarim konkurs',
        'prevara',
        'fraud',
        'hakujem',
        'hakovanje',
        'hacking',
        'hack',
        'varanje',
        'cheat',
        'namesti konkurs',
        'namestim konkurs',
        'zaobiđem uslove',
        'zaobidjem uslove',
    ];

    // Izraz mora da počne na početku reči („falsifik" hvata i „falsifikat"), a ne usred nje; pismo i dijakritici se ne računaju.
    public function jeOpasno(string $pitanje): bool
    {
        $tekst = ' '.PoredjenjeTeksta::normalizuj($pitanje);

        foreach (self::IZRAZI as $izraz) {
            if (str_contains($tekst, ' '.PoredjenjeTeksta::normalizuj($izraz))) {
                return true;
            }
        }

        return false;
    }
}
