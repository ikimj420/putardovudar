<?php

namespace App\Services\Pomocnik;

final readonly class OdgovorPomocnika
{
    /**
     * @param  list<Izvor>  $izvori
     * @param  string|null  $razlogSablona  zašto je umesto odgovora modela stao šablon, ili null kad je odgovor modela prošao proveru
     * @param  bool  $odbijeno  pitanje je opasno: odgovor je jedna rečenica, a model i pretraga nisu pozvani
     * @param  list<PonudaSablona>  $ponude  šabloni koje pomoćnik nudi (traženje da se nešto napiše); tada model nije pozvan
     */
    public function __construct(
        public string $tekst,
        public array $izvori,
        public bool $nemaPodatak,
        public ?string $razlogSablona = null,
        public bool $odbijeno = false,
        public array $ponude = [],
    ) {}
}
