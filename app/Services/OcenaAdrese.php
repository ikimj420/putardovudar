<?php

namespace App\Services;

// Tri različite stvari: adresa je javna, adresa je nejavna (petlja, privatna ili „link-local" mreža, ili nije http/https), ili se ime iz nje ne razrešava ni na šta.
// Poslednje je isto što i strana koja ne odgovara: DNS ume samo trenutno da ne odgovori, pa to nije odluka (paket 99).
enum OcenaAdrese
{
    case Javna;
    case Nejavna;
    case NeRazresava;
}
