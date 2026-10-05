<?php

namespace App\Services\Ollama;

use RuntimeException;

// Ollama ne radi ili ne odgovara: poziv koji je ovo bacio nema smisla ponavljati dok se ne pokrene.
final class OllamaNedostupna extends RuntimeException {}
