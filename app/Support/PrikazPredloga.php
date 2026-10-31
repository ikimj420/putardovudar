<?php

namespace App\Support;

use App\Models\Predlog;

// Tekstovi strane „Predloži priliku" koje vidi posetilac, na jednom mestu (kao PrikazPomocnika). Naslov, uvod, polja, natpis i zahvalnica su iz paketa 79;
// objašnjenja ispod polja, primer, dugme i poruke o grešci su predlog (PITANJA-79.md), Ivan odobrava.
final class PrikazPredloga
{
    public const NASLOV = 'Predloži priliku';

    public const UVOD = 'Znaš za posao, stipendiju ili konkurs koji nije na sajtu? Pošalji nam link.';

    public const OZNAKA_LINKA = 'Link do prilike';

    public const OBJASNJENJE_LINKA = 'Adresa strane na kojoj piše o prilici.';

    public const PRIMER_LINKA = 'https://primer.rs/konkurs-za-stipendije';

    public const OZNAKA_NAPOMENE = 'Napomena (nije obavezna)';

    public const OBJASNJENJE_NAPOMENE = 'Šta je to i do kada traje, ako znaš. Najviše '.Predlog::NAJVISE_ZNAKOVA_NAPOMENE.' znakova.';

    public const PRIMER_NAPOMENE = 'Na primer: Konkurs za stipendije, prijave su do 15. novembra.';

    public const NE_CUVAMO = 'Čuvamo samo link i napomenu, da bismo proverili priliku. Ne upisuj lične podatke.';

    public const DUGME = 'Pošalji';

    public const HVALA = 'Hvala! Predlog je stigao.';

    public const GRESKA_PRAZNO = 'Upiši link do prilike.';

    public const GRESKA_LINK = 'Link nije ispravan. Mora da počne sa http:// ili https://.';

    public const GRESKA_LINK_DUGACAK = 'Link može imati najviše '.Predlog::NAJVISE_ZNAKOVA_LINKA.' znakova.';

    public const GRESKA_NAPOMENA_DUGACKA = 'Napomena može imati najviše '.Predlog::NAJVISE_ZNAKOVA_NAPOMENE.' znakova.';

    public const PREVISE_PREDLOGA = 'Predlozi stižu prebrzo. Probaj ponovo za minut.';

    // Veza sa strane „Prilike" (podnožje dobija vezu u paketu 80).
    public const VEZA_SA_PRILIKA = 'Znaš za priliku koje nema? Predloži je.';
}
