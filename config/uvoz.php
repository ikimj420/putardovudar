<?php

return [

    // Novi izvor se dodaje ovde (ime i adresa); komanda uvoz:rss ga uvozi bez izmene koda.
    'izvori' => [
        ['ime' => 'Fond za mlade talente', 'adresa' => 'https://fondzamladetalente.gov.rs/feed/'],
        ['ime' => 'Erasmus+ Srbija', 'adresa' => 'https://erasmusplus.rs/feed/'],
        ['ime' => 'EU mogućnosti - konkursi', 'adresa' => 'https://eumogucnosti.rs/category/konkursi/feed/'],
        ['ime' => 'Youth.rs', 'adresa' => 'https://youth.rs/feed/'],
        ['ime' => 'EU u Srbiji - konkursi', 'adresa' => 'https://europa.rs/category/konkursi/feed/'],
        ['ime' => 'KOMS - konkursi', 'adresa' => 'https://koms.rs/category/konkursi/feed/'],
        ['ime' => 'Ministarstvo prosvete', 'adresa' => 'https://prosveta.gov.rs/kategorija/vazna-obavestenja/feed/'],
        // Godina je u adresi: u januaru 2027. adresa se menja (konkursi-i-javni-pozivi-2027).
        ['ime' => 'Ministarstvo za ljudska i manjinska prava', 'adresa' => 'https://minljmpdd.gov.rs/category/konkursi-i-javni-pozivi-2026/feed/'],
        ['ime' => 'Infostud Poslovi', 'adresa' => 'https://rss.infostud.com/poslovi/rss.php'],
    ],

    // Izvori bez RSS-a: strana sa spiskom poziva (HTML). Uvozi ih uvoz:spiskovi; čita se samo prva strana spiska, a naslov sa „ZATVOREN" se preskače.
    'spiskovi' => [
        ['ime' => 'Nacionalna služba za zapošljavanje - konkursi', 'adresa' => 'https://www.nsz.gov.rs/sadrzaji/nzs-konkursi/10'],
        ['ime' => 'Nacionalna služba za zapošljavanje - regionalni i lokalni konkursi', 'adresa' => 'https://www.nsz.gov.rs/sadrzaji/konkurisi-lokalne-samouprave/11'],
    ],

    // Ograničenja da jedan izvor ne može da zaglavi uvoz ni da napuni bazu.
    'najvise_stavki' => 50,
    'vreme_cekanja' => 30,
    'najvise_bajtova' => 2_000_000,
    'korisnicki_agent' => 'PutardoVudar/0.1 (local learning project)',

];
