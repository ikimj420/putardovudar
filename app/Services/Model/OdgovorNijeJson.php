<?php

namespace App\Services\Model;

use RuntimeException;

// Model radi, ali odgovor nije JSON objekat: kvari se jedan odgovor, ne usluga.
final class OdgovorNijeJson extends RuntimeException {}
