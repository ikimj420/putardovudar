<?php

return [

    // Podešavanja okruženja: Ollama radi lokalno, a model se menja brže od aplikacije.
    'adresa' => env('OLLAMA_ADRESA', 'http://127.0.0.1:11434'),
    'model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),
    'vreme_cekanja' => (int) env('OLLAMA_VREME_CEKANJA', 120),

    // Ollama podrazumevano čita samo 2-4 hiljade tokena i tiho odseče ostatak teksta.
    'kontekst' => 8192,

    // Koliko znakova teksta strane ide modelu; više od toga ne staje u kontekst.
    'najvise_znakova' => 12000,

];
