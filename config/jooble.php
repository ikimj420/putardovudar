<?php

return [

    // Ime izvora u bazi; uvoz:obradi po njemu prepoznaje nacrte iz Jooble-a.
    'ime' => 'Jooble',

    // Ključ se izdaje na regionalnom domenu (za Srbiju rs.jooble.org) i važi samo za tu zemlju. Bez ključa uvoz se preskače.
    'kljuc' => env('JOOBLE_KLJUC'),

    'adresa' => 'https://jooble.org/api/',

    // Polje „keywords" je po dokumentaciji obavezno, pa se ovde zadaje šta se traži.
    'kljucne_reci' => env('JOOBLE_KLJUCNE_RECI', 'posao'),
    'lokacija' => env('JOOBLE_LOKACIJA', 'Srbija'),

    'najvise_poslova' => 50,

];
