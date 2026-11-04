<?php

namespace App\Services;

// Posebna vrsta „strana nedostupna": nije kvar veze koji prođe, nego odluka (adresa je petlja, privatna ili link-local mreža, ili se ime razrešilo na takvu), pa obrada nacrt ostavlja sa ovim razlogom.
// Ime koje se ne razrešava ni na šta nije ovde: to je običan StranaNedostupna, jer DNS ume samo trenutno da ne odgovori.
final class AdresaNijeJavna extends StranaNedostupna
{
    public const PORUKA = 'adresa izvora nije javna';

    public function __construct()
    {
        parent::__construct(self::PORUKA);
    }
}
