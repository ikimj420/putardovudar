<?php

return [

    // Obrada prilika (uvoz:obradi), pomoćnik i „Napiši moj" idu preko Claude API-ja (Ivan, 05.11.2026.: „sve treba preko Claude-a"); Ollama je izbačena (paket 100).
    // Ključ je samo u okruženju (.env), nikad u kodu, testu, logu ni izveštaju.
    'model_obrade' => env('MODEL_OBRADE', 'claude'),

    'kljuc' => env('ANTHROPIC_API_KEY'),
    'model' => env('CLAUDE_MODEL', 'claude-haiku-5-5'),
    'adresa' => env('CLAUDE_ADRESA', 'https://api.anthropic.com'),
    'verzija_api' => '2023-06-01',
    'vreme_cekanja' => (int) env('CLAUDE_VREME_CEKANJA', 120),

    // Odgovor obrade je mali JSON, ali Haiku 5.5 podrazumevano „razmišlja" i ti tokeni ulaze u izlaz (izmereno 13.10.2026.: sa 1500 je 7 od 80 odgovora ostalo bez teksta),
    // pa je granica velika; cenu određuje ono što je stvarno potrošeno, ne granica.
    'najvise_izlaznih_tokena' => 4000,

    // null: kako model podrazumeva; „iskljuceno": šalje se thinking disabled (Haiku 5.5 ga prima, jeftinije i brže; Sonnet 5.5 ne razmišlja pa mu ne treba i odbija taj oblik).
    'razmisljanje' => env('CLAUDE_RAZMISLJANJE'),

    // Modeli 5.5 ne primaju „temperature" (HTTP 400: „deprecated for this model", izmereno 13.10.2026.), pa se ne šalje; za stariji model može da se zada.
    'temperatura' => null,

    // Najviše poziva modela u jednom prolazu uvoz:obradi, za Ollamu i za Claude; posle toga obrada staje, a ostatak čeka sledeći prolaz (paket 95, PITANJA-92 5).
    // Najviše nacrta obrađenih u jednom danu je 336 (obradeno_at, 30.10.2026.), pa je granica iznad toga; broj je Ivanov (PITANJA-95.md).
    'najvise_poziva_po_prolazu' => (int) env('OBRADA_NAJVISE_POZIVA', 500),

    // Koliko znakova teksta strane ide modelu; isto kao kod Ollame.
    'najvise_znakova' => 12000,

    // 429 i 529: čeka se ovoliko sekundi pre svakog od najviše dva ponavljanja (ili koliko odgovor traži, do 30 s), pa se odustaje.
    'ponavljanja_cekanje' => [3, 10],

    // Cene u dolarima po milionu tokena (upiti do 100.000 tokena); izvor: platform.claude.com/docs/en/about-claude/pricing, 13.10.2026. Batch API je 50 % niži (ne koristi se).
    'cene' => [
        'claude-haiku-5-5' => ['ulaz' => 0.10, 'izlaz' => 0.50],
        'claude-sonnet-5-5' => ['ulaz' => 2.00, 'izlaz' => 10.00],
    ],

];
