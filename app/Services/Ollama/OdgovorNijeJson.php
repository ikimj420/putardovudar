<?php

namespace App\Services\Ollama;

use RuntimeException;

// Ollama radi, ali odgovor nije JSON objekat: kvari se jedan odgovor, ne usluga.
final class OdgovorNijeJson extends RuntimeException {}
