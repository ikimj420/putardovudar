<?php

return [

    // Obrada prilika (uvoz:obradi) može da ide preko Claude API-ja umesto lokalne Ollame; pomoćnik i „Napiši moj" uvek ostaju na Ollami (paket 71).
    // Ključ je samo u okruženju (.env), nikad u kodu, testu, logu ni izveštaju.
    'model_obrade' => env('MODEL_OBRADE', 'ollama'),

    'kljuc' => env('ANTHROPIC_API_KEY'),
    'model' => env('CLAUDE_MODEL', 'claude-haiku-5-5'),
    'adresa' => env('CLAUDE_ADRESA', 'https://api.anthropic.com'),
    'verzija_api' => '2023-06-01',
    'vreme_cekanja' => (int) env('CLAUDE_VREME_CEKANJA', 120),

    // Odgovor obrade je mali JSON; više od ovoga je odgovor koji se ionako odbacuje.
    'najvise_izlaznih_tokena' => 1500,

    // Modeli 5.5 ne primaju „temperature" (HTTP 400: „deprecated for this model", izmereno 13.10.2026.), pa se ne šalje; za stariji model može da se zada.
    'temperatura' => null,

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
