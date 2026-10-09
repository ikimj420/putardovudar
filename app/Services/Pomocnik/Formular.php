<?php

namespace App\Services\Pomocnik;

use App\Enums\VrstaPrilike;

// Vrednosti koje je kod prihvatio iz modelovog formulara; šta ne prođe proveru, ovde nikad ne stiže.
final readonly class Formular
{
    /** @param  list<string>  $kljucneReci */
    public function __construct(
        public ?VrstaPrilike $vrsta = null,
        public ?string $grad = null,
        public ?string $kome = null,
        public array $kljucneReci = [],
    ) {}

    public function jePrazan(): bool
    {
        return $this->vrsta === null && $this->grad === null && $this->kome === null && $this->kljucneReci === [];
    }
}
