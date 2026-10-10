<?php

namespace App\Support;

use App\Models\Organizacija;

// Tekstovi o organizacijama koje vidi posetilac, na jednom mestu (kao PrikazPrilike). Predlog je u PITANJA-18-22.md.
final class PrikazOrganizacija
{
    public const NASLOV = 'Organizacije';

    // Oznaka na kartici organizacije uz odgovor pomoćnika.
    public const OZNAKA = 'Organizacija';

    public const NEMA_ORGANIZACIJA = 'Trenutno nema organizacija.';

    // Predlog; Ivan odobrava (PITANJA-23-25.md). Ne kaže da je organizacija nacrt, jer bi to potvrdilo da zapis postoji.
    public const NEMA_STRANE = 'Ta strana ne postoji ili organizacija više nije dostupna.';

    public const MESTO = 'Mesto';

    public const ONLINE = 'Online';

    public const TELEFON = 'Telefon';

    // Predlog; Ivan odobrava (PITANJA-26-38.md, paket 37).
    public const EPOSTA = 'E-pošta';

    public const SAJT = 'Sajt';

    public const USLUGE = 'Usluge';

    // Link se piše samo ako je veb adresa: sajt u bazi može da stigne i mimo pravila objave (masovni upit).
    public static function linkSajta(Organizacija $organizacija): ?string
    {
        return LinkSajta::jeIspravan($organizacija->sajt) ? trim((string) $organizacija->sajt) : null;
    }

    // Isto kao link sajta: adresa u bazi može da stigne i mimo admina, pa se veza „mailto:" pravi samo od ispravne.
    public static function eposta(Organizacija $organizacija): ?string
    {
        return EpostaAdresa::jeIspravna($organizacija->eposta) ? trim((string) $organizacija->eposta) : null;
    }

    public static function nazivSajta(string $link): string
    {
        return (string) (parse_url($link, PHP_URL_HOST) ?: $link);
    }
}
