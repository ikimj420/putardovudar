<?php

namespace App\Services;

use RuntimeException;

// $status je HTTP status kad je strana odgovorila, a null kad nije (greška veze, prevelik odgovor, drugo pismo, prazna strana); dnevna provera samo 404 i 410 čita kao „strana ne postoji".
class StranaNedostupna extends RuntimeException
{
    // Isti tekst za svaki razlog zbog kog veza nije uspela (nema odgovora, ime se ne razrešava): ništa od toga nije odluka, pa se pokušava ponovo.
    public const NE_ODGOVARA = 'strana ne odgovara';

    public function __construct(string $poruka, public readonly ?int $status = null)
    {
        parent::__construct($poruka);
    }
}
