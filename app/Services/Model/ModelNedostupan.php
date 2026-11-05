<?php

namespace App\Services\Model;

use RuntimeException;

// Model ne radi ili ne odgovara (veza, ključ, odbijen zahtev): poziv koji je ovo bacio nema smisla ponavljati dok se ne popravi.
final class ModelNedostupan extends RuntimeException {}
