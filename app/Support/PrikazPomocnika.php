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

    public const PREVISE_PITANJA = 'Pitanja stižu prebrzo. Probaj ponovo za minut.';

    // Plutajući prozor (paket 55); tekstovi su predlog, Ivan odobrava (PITANJA-55-56.md).
    public const ZATVORI = 'Zatvori';

    public const TRAZIM = 'Tražim odgovor…';

    // Ime i dugme (Ivan, 10.10.2026., paket 57/T9): dugme na početnoj i dole desno, naslov prozora i strane „Vudar pomoćnik" (kao na starom sajtu).
    public const PITAJ_VUDAR = 'Pitaj Vudar pomoćnika';

    // „Napiši moj" (paket 56): pomoćnik piše tekst po šablonu iz onoga što čovek upiše; tekstovi su predlog, Ivan odobrava (PITANJA-55-56.md).
    public const NAPISI_MOJ = 'Napiši moj';

    public const NAPISI_PONUDA = 'Napiši';

    public const NAPISI = 'Napiši tekst';

    public const NASLOV_TEKSTA = 'Tvoj tekst';

    public const NAPOMENA_NAPISAN = 'Tekst je napisan iz tvojih odgovora. Proveri ga pre nego što ga pošalješ.';

    public const NAPOMENA_SLOZEN = 'Složen je šablon sa tvojim odgovorima. Linije označene crtama popuni olovkom.';

    public const NEMA_SABLONA_STRANE = 'Ta strana ne postoji ili šablon više nije dostupan.';
}
