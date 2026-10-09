<?php

namespace App\Support;

// Tekstovi strane pomoćnika koje vidi posetilac, na jednom mestu (kao PrikazPrilike). Predlog je u PITANJA-NOC-1.md.
final class PrikazPomocnika
{
    public const NASLOV = 'Pomoćnik';

    public const UVOD = 'Postavi pitanje o prilikama. Odgovaram samo iz baze, uz izvor.';

    public const OZNAKA_POLJA = 'Tvoje pitanje';

    public const PRIMER = 'Na primer: Ima li posla u Nišu?';

    public const DUGME = 'Pitaj';

    public const NASLOV_ODGOVORA = 'Odgovor';

    public const NASLOV_IZVORA = 'Izvori';

    public const NE_RADI = 'Pomoćnik trenutno ne radi. Probaj kasnije.';

    public const GRESKA_PRAZNO = 'Upiši pitanje.';

    public const GRESKA_DUGACKO = 'Pitanje može imati najviše 300 znakova.';

    public const LINK_SA_POCETNE = 'Pitaj pomoćnika';

    public const UVOD_LINKA = 'Ne nalaziš šta tražiš?';
}
