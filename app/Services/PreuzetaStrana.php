<?php

namespace App\Services;

// Strana izvora kako je preuzeta: vidljiv tekst latinicom (ide modelu) i izvorni HTML (iz njega se čita samo ono što izvor posebno kaže, kao RokSaInfostuda).
final readonly class PreuzetaStrana
{
    public function __construct(public string $tekst, public string $html) {}
}
