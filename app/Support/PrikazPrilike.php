<?php

namespace App\Support;

use App\Models\Prilika;

// Tekstovi o prilici koje vidi posetilac, na jednom mestu, da kartica i strana ne pišu isto na dva načina.
final class PrikazPrilike
{
    public const NEMA_PRILIKA = 'Trenutno nema otvorenih prilika.';

    public const NEMA_STRANE = 'Ta strana ne postoji ili prilika više nije otvorena.';

    public const ZVANICNI_IZVOR = 'Zvanični izvor: ';

    public const PROVERI_IZVOR = 'Pre prijave proveri podatke na zvaničnom izvoru.';

    public static function rok(Prilika $prilika): string
    {
        if ($prilika->rok_stalno_otvoren) {
            return 'Prijave stalno otvorene';
        }

        return $prilika->rok === null ? 'Bez roka' : 'Rok: '.Prikaz::datum($prilika->rok);
    }

    // „Online" ima prednost: polje Online znači da se sve radi preko interneta, pa mesto nije bitno.
    public static function mesto(Prilika $prilika): ?string
    {
        if ($prilika->online) {
            return 'Online';
        }

        return filled($prilika->mesto) ? $prilika->mesto : null;
    }

    // Link se piše samo ako je veb adresa: izvor u bazi može da stigne i mimo pravila objave (masovni upit).
    public static function linkIzvora(Prilika $prilika): ?string
    {
        $link = trim((string) $prilika->link_izvora);

        return str_starts_with($link, 'http://') || str_starts_with($link, 'https://') ? $link : null;
    }

    public static function nazivIzvora(Prilika $prilika): ?string
    {
        if (filled($prilika->naziv_izvora)) {
            return $prilika->naziv_izvora;
        }

        $adresa = self::linkIzvora($prilika);

        return $adresa === null ? null : (parse_url($adresa, PHP_URL_HOST) ?: $adresa);
    }
}
