<?php

namespace App\Support;

use App\Models\Prilika;

// Tekstovi o prilici koje vidi posetilac, na jednom mestu, da kartica i strana ne pišu isto na dva načina.
final class PrikazPrilike
{
    public const NEMA_PRILIKA = 'Trenutno nema otvorenih prilika.';

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
}
