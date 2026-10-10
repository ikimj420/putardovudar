<?php

namespace App\Support;

use App\Models\Prilika;

// Tekstovi o prilici koje vidi posetilac, na jednom mestu, da kartica i strana ne pišu isto na dva načina.
final class PrikazPrilike
{
    public const NEMA_PRILIKA = 'Trenutno nema otvorenih prilika.';

    public const SVE_PRILIKE = 'Sve prilike';

    // Naziv grupe dugmadi za filter, čita ga čitač ekrana; predlog je u PITANJA-26-38.md.
    public const FILTERI = 'Brzi filteri';

    public const NEMA_STRANE = 'Ta strana ne postoji ili prilika više nije otvorena.';

    public const ZVANICNI_IZVOR = 'Zvanični izvor: ';

    public const PROVERI_IZVOR = 'Pre prijave proveri podatke na zvaničnom izvoru.';

    public const BEZ_ROKA = 'Bez roka';

    public const STALNO_OTVORENO = 'Prijave stalno otvorene';

    // Oznaka na kartici u adminu; sajt je ne piše jer prilike sa prošlim rokom ne prikazuje.
    public const ROK_PROSAO = 'Rok prošao';

    public static function rok(Prilika $prilika): string
    {
        if ($prilika->rok_stalno_otvoren) {
            return self::STALNO_OTVORENO;
        }

        return $prilika->rok === null ? self::BEZ_ROKA : 'Rok: '.Prikaz::datum($prilika->rok);
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
