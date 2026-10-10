<?php

return [

    // Ukupno za oba poziva modela. PHP prekida zahtev posle 30 s (Herd: max_execution_time) i računa i čekanje na model,
    // pa ostaje 5 s za bazu i ispis strane.
    'ukupno_za_pitanje' => (int) env('POMOCNIK_UKUPNO_ZA_PITANJE', 25),

    // Ispod ovoga model se ne zove drugi put, jer odgovor ne bi stigao na vreme.
    'najmanje_za_odgovor' => 5,

    // Koliko pitanja jedna adresa sme da pošalje u minuti.
    'pitanja_u_minuti' => (int) env('POMOCNIK_PITANJA_U_MINUTI', 5),

];
