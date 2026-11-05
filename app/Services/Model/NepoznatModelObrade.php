<?php

namespace App\Services\Model;

use RuntimeException;

// Vrednost MODEL_OBRADE se ne ispisuje: ako je neko tu zalepio nešto što nije ime modela (npr. ključ), poruka ga ne bi smela da odnese u ispis ni u dnevnik.
final class NepoznatModelObrade extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nepoznata vrednost MODEL_OBRADE: dozvoljeno je samo „claude", tačno tako, malim slovima.');
    }
}
