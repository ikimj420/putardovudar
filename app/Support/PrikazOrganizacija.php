<?php

namespace App\Support;

use App\Models\Organizacija;

// Tekstovi o organizacijama koje vidi posetilac, na jednom mestu (kao PrikazPrilike). Predlog je u PITANJA-18-22.md.
final class PrikazOrganizacija
{
    public const NASLOV = 'Organizacije';

    public const NEMA_ORGANIZACIJA = 'Trenutno nema organizacija.';

    public const MESTO = 'Mesto';

    public const ONLINE = 'Online';

    public const TELEFON = 'Telefon';

    public const SAJT = 'Sajt';

    public const USLUGE = 'Usluge';

    // Link se piše samo ako je veb adresa: sajt u bazi može da stigne i mimo pravila objave (masovni upit).
    public static function linkSajta(Organizacija $organizacija): ?string
    {
        return LinkSajta::jeIspravan($organizacija->sajt) ? trim((string) $organizacija->sajt) : null;
    }

    public static function nazivSajta(string $link): string
    {
        return (string) (parse_url($link, PHP_URL_HOST) ?: $link);
    }
}
