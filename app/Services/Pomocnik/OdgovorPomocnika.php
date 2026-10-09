<?php

namespace App\Services\Pomocnik;

final readonly class OdgovorPomocnika
{
    /**
     * @param  list<Izvor>  $izvori
     * @param  string|null  $razlogSablona  zašto je umesto odgovora modela stao šablon, ili null kad je odgovor modela prošao proveru
     */
    public function __construct(
        public string $tekst,
        public array $izvori,
        public bool $nemaPodatak,
        public ?string $razlogSablona = null,
    ) {}

    public function jeIzSablona(): bool
    {
        return $this->razlogSablona !== null;
    }
}
