<?php

namespace App\Services;

final readonly class RezultatUvoza
{
    public function __construct(
        public int $novih = 0,
        public int $preskoceno = 0,
        public ?string $greska = null,
    ) {}
}
