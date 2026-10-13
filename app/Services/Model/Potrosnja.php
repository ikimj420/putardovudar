<?php

namespace App\Services\Model;

// Šta je model potrošio dok je trajala obrada: broj poziva, tokeni i procena cene u dolarima (null kad model radi lokalno).
final readonly class Potrosnja
{
    public function __construct(
        public string $model,
        public int $poziva,
        public int $ulazniTokeni,
        public int $izlazniTokeni,
        public ?float $cena = null,
    ) {}
}
