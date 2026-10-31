<?php

return [

    // Novi izvor se dodaje ovde (ime i adresa); komanda uvoz:rss ga uvozi bez izmene koda.
    // europa.rs nije ovde: nema kategoriju konkursa, adresa /category/konkursi/feed/ daje ispravan RSS bez ijedne stavke (izmereno 13.10.2026., PITANJA-74).
    'izvori' => [
        ['ime' => 'Fond za mlade talente', 'adresa' => 'https://fondzamladetalente.gov.rs/feed/'],
        ['ime' => 'Erasmus+ Srbija', 'adresa' => 'https://erasmusplus.rs/feed/'],
        ['ime' => 'EU mogućnosti - konkursi', 'adresa' => 'https://eumogucnosti.rs/category/konkursi/feed/'],
        ['ime' => 'Youth.rs', 'adresa' => 'https://youth.rs/feed/'],
        ['ime' => 'KOMS - konkursi', 'adresa' => 'https://koms.rs/category/konkursi/feed/'],
        ['ime' => 'Ministarstvo prosvete', 'adresa' => 'https://prosveta.gov.rs/kategorija/vazna-obavestenja/feed/'],
        // Godina je u adresi: u januaru 2027. adresa se menja (konkursi-i-javni-pozivi-2027).
        ['ime' => 'Ministarstvo za ljudska i manjinska prava', 'adresa' => 'https://minljmpdd.gov.rs/category/konkursi-i-javni-pozivi-2026/feed/'],
        // Infostud vraća istih 20 poslednjih oglasa na svakoj strani (izmereno 13.10.2026.), pa se čita samo prva.
        ['ime' => 'Infostud Poslovi', 'adresa' => 'https://rss.infostud.com/poslovi/rss.php', 'najvise_strana' => 1],
        // Sajtovi romskih organizacija (paket 78, izmereno 29.10.2026.). Opre Roma Srbija nije ovde: nema RSS (adrese /feed i /rss vode na početnu stranu u HTML-u), pa je u spiskovima.
        // ERIAC i REF nisu ovde: pišu na engleskom. Forum Roma Srbije ima i engleski feed (/en/feed/), koji se ne uvozi.
        ['ime' => 'Forum Roma Srbije', 'adresa' => 'https://www.frs.org.rs/feed/'],
        ['ime' => 'Kancelarija za inkluziju Roma', 'adresa' => 'https://inkluzijaromavojvodina.rs/feed/'],
        ['ime' => 'Romska ženska mreža', 'adresa' => 'https://rzm.rs/feed/'],
        ['ime' => 'Bibija', 'adresa' => 'https://www.bibija.org.rs/feed/'],
        ['ime' => 'Rromanipen', 'adresa' => 'https://www.romanipen.org.rs/feed/'],
        ['ime' => 'ROSA', 'adresa' => 'https://rosale.org/feed/'],
        ['ime' => 'Osvit', 'adresa' => 'https://osvit.rs/feed/'],
        // Joomla: ne zna „paged" (druga strana je ista kao prva, izmereno), pa se čita samo prva.
        ['ime' => 'Udruženje Roma Novi Bečej', 'adresa' => 'https://udruzenjeromanb.org.rs/vesti?format=feed&type=rss', 'najvise_strana' => 1],
    ],

    // Izvori bez RSS-a: strana sa spiskom poziva (HTML). Uvozi ih uvoz:spiskovi; čita se samo prva strana spiska, a naslov sa „ZATVOREN" se preskače.
    // „izgled" je ključ iz UvozSpiska::IZGLEDI (bez njega „nsz"). Opre Roma: srpska verzija je /rs/vesti (/rom/vesti je na romskom), a datum na strani nema godinu.
    'spiskovi' => [
        ['ime' => 'Nacionalna služba za zapošljavanje - konkursi', 'adresa' => 'https://www.nsz.gov.rs/sadrzaji/nzs-konkursi/10'],
        ['ime' => 'Opre Roma Srbija - vesti', 'adresa' => 'https://opreromasrbija.rs/rs/vesti', 'izgled' => 'opre-roma'],
        // Ministarstvo turizma i omladine (paket 87, izmereno 30.10.2026.): feed (/rss) ima ćirilično „о" u adresi sajta, pa veze iz njega ne rade, a stavke su vesti. Godina je u adresi: u januaru 2027. adrese se menjaju.
        ['ime' => 'Ministarstvo turizma i omladine - konkursi', 'adresa' => 'https://mto.gov.rs/tekst/13534/konkursi-2026-godina.php', 'izgled' => 'mto'],
        ['ime' => 'Ministarstvo turizma i omladine - javni pozivi', 'adresa' => 'https://mto.gov.rs/tekst/13588/javni-pozivi-2026-godina.php', 'izgled' => 'mto'],
    ],

    // Ograničenja da jedan izvor ne može da zaglavi uvoz ni da napuni bazu.
    // Strane po izvoru (?paged=2…): izvor koji ne zna „paged" ima svoj „najvise_strana" => 1 uz ime i adresu.
    'najvise_strana' => 5,
    'najvise_stavki' => 50,
    // Najviše prilika koje dnevna provera sme da vrati u nacrt u jednom prolazu (prilike:provera); više od toga znači da je izvor verovatno promenio izgled, pa se ne upisuje ništa.
    'provera_najvise_u_nacrt' => 10,
    'vreme_cekanja' => 30,
    // Koliko preusmeravanja se prati pre odbijanja; isto koliko je ranije pratio HTTP klijent sam (Guzzle, 5).
    'najvise_preusmeravanja' => 5,
    'najvise_bajtova' => 2_000_000,
    'korisnicki_agent' => 'PutardoVudar/0.1 (local learning project)',

];
