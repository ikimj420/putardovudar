<?php

return [

    // Novi izvor se dodaje ovde (ime i adresa); komanda uvoz:rss ga uvozi bez izmene koda.
    'izvori' => [
        ['ime' => 'Fond za mlade talente', 'adresa' => 'https://fondzamladetalente.gov.rs/feed/'],
        ['ime' => 'Erasmus+ Srbija', 'adresa' => 'https://erasmusplus.rs/feed/'],
        ['ime' => 'EU mogućnosti - konkursi', 'adresa' => 'https://eumogucnosti.rs/category/konkursi/feed/'],
        ['ime' => 'Youth.rs', 'adresa' => 'https://youth.rs/feed/'],
        ['ime' => 'EU u Srbiji - konkursi', 'adresa' => 'https://europa.rs/category/konkursi/feed/'],
    ],

    // Ograničenja da jedan izvor ne može da zaglavi uvoz ni da napuni bazu.
    'najvise_stavki' => 50,
    'vreme_cekanja' => 30,
    'najvise_bajtova' => 2_000_000,
    'korisnicki_agent' => 'PutardoVudar/0.1 (local learning project)',

];
