<?php

namespace App\Support;

// Tekstovi početne strane koje vidi posetilac, na jednom mestu (kao PrikazPrilike). Tekstovi su iz predloga izgleda (paket 57); Ivan odobrava (PITANJA-57.md).
final class PrikazPocetne
{
    public const NASLOV_STRANE = 'Početna';

    public const NASLOV = 'Otvorena vrata za ';

    public const NASLOV_ISTAKNUTO = 'tvoju priliku.';

    public const OPIS = 'Sve prilike na jednom mestu, sa zvaničnim izvorom. Za učenike, studente, roditelje i sve koji traže posao.';

    public const PRILIKE_DUGME = 'Pogledaj prilike';

    public const SVE_PRILIKE_DUGME = 'Sve prilike';

    public const PRILIKE = 'Prilike';

    // Traka „Vodiči" (predlog iz izgleda, prilagođen: nema svaki vodič primer; Ivan odobrava).
    public const TRAKA_NATPIS = 'Vodiči';

    public const TRAKA_NASLOV = 'Napiši pismo, CV ili molbu.';

    public const TRAKA_OPIS = 'Vodiči te vode korak po korak, a neki imaju i gotov primer. Gde piše „Napiši moj", odgovori na par pitanja, a pomoćnik napiše tvoj tekst.';

    public const TRAKA_DUGME = 'Otvori vodiče';

    // Pločice sa brojevima iz baze: uz broj 1, uz 2 do 4 i uz 5 ili više (predlog iz izgleda; Ivan odobrava).
    public const PLOCICE_NAZIV = 'Brojevi na sajtu';

    public const PLOCICA_PRILIKE = ['otvorena prilika', 'otvorene prilike', 'otvorenih prilika'];

    public const PLOCICA_VODICI = ['vodič', 'vodiča', 'vodiča'];

    public const PLOCICA_ORGANIZACIJE = ['organizacija za pomoć', 'organizacije za pomoć', 'organizacija za pomoć'];

    public const PLOCICA_KALENDAR = ['mesec u kalendaru', 'meseca u kalendaru', 'meseci u kalendaru'];
}
