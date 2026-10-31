<?php

namespace App\Services;

// Posebna vrsta „strana nedostupna": nije kvar veze koji prođe, nego odluka, pa obrada nacrt ostavlja sa ovim razlogom umesto da ga pokušava svaki dan.
final class AdresaNijeJavna extends StranaNedostupna
{
    public const PORUKA = 'adresa izvora nije javna';

    public function __construct()
    {
        parent::__construct(self::PORUKA);
    }
}
