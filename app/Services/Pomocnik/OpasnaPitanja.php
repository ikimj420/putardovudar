<?php

namespace App\Services\Pomocnik;

use App\Support\PoredjenjeTeksta;

// Odbija se namera (kako da JA lažiram, hakujem, prevarim), ne reč: pitanje žrtve i obične reči sa istim početkom prolaze.
// Stabla se ovde ne koriste, jer bi „hack" hvatao i „hackathon". Pismo i dijakritici se ne računaju, kao u ostalom poređenju.
final class OpasnaPitanja
{
    // Oblici namere: infinitiv i prvo lice (jednina i množina). Trećeg lica, prošlog vremena, glagolskih imenica i trpnog
    // prideva nema namerno („prevario me je poslodavac", „zaštita od hakovanja", „hakovan nalog" pitaju žrtve).
    // „Namesti konkurs" i „zaobiđem uslove" traže i predmet, jer samo glagol ne govori ništa.
    private const OBLICI = [
        '\blazir(?:ati|am|amo)\b',
        '\bfalsifik(?:ovati|ujem|ujemo)\b',
        '\bhak(?:ovati|ujem|ujemo)\b',
        '\bprevar(?:iti|im|imo)\b',
        '\bvar(?:ati|am|amo)\b',
        '\bnamest(?:iti|im|imo|ati|am|amo)\b(?:\s+\w+){0,3}?\s+konkurs\w*',
        '\bzaobi(?:ci|dem|djem|demo|djemo)\b(?:\s+\w+){0,3}?\s+uslov\w*',
        // Engleski se ne obrađuje, ali stari spisak ga je imao; samo izrazi sa glagolom ili imenicom koja traži krivotvorenje.
        '\bto (?:hack|cheat|forge)\b',
        '\bto commit fraud\b',
        '\bfake documents?\b',
    ];

    public function jeOpasno(string $pitanje): bool
    {
        $tekst = PoredjenjeTeksta::normalizuj($pitanje);

        foreach (self::OBLICI as $oblik) {
            if (preg_match('/'.$oblik.'/', $tekst) === 1) {
                return true;
            }
        }

        return false;
    }
}
