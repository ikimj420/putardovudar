<?php

namespace App\Services;

use RuntimeException;

// $status je HTTP status kad je strana odgovorila, a null kad nije (greška veze, prevelik odgovor, drugo pismo, prazna strana); dnevna provera samo 404 i 410 čita kao „strana ne postoji".
final class StranaNedostupna extends RuntimeException
{
    public function __construct(string $poruka, public readonly ?int $status = null)
    {
        parent::__construct($poruka);
    }
}
