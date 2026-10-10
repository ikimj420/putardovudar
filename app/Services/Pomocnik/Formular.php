<?php

namespace App\Services\Pomocnik;

use App\Enums\Grupa;
use App\Enums\VrstaPrilike;

// Vrednosti koje je kod prihvatio iz modelovog formulara; šta ne prođe proveru, ovde nikad ne stiže.
final readonly class Formular
{
    /** @var list<string> */
    public array $sveKljucneReci;

    /**
     * @param  list<string>  $kljucneReci  reči za prilike: bez onih koje samo ponavljaju vrstu, grad ili „kome" (to rade kolone)
     * @param  list<string>|null  $sveKljucneReci  sve prihvaćene reči osim onih koje ponavljaju grad; null znači iste kao $kljucneReci
     * @param  bool  $vrstaJePomenuta  pitanje samo kaže vrstu (posao, stipendija...); inače je vrsta nagađanje modela
     * @param  list<Grupa>  $grupe  grupe čije reči stoje u pitanju (ja sam student, za Rome...); porede se sa grupama prilike, ne kao reči
     */
    public function __construct(
        public ?VrstaPrilike $vrsta = null,
        public ?string $grad = null,
        public ?string $kome = null,
        public array $kljucneReci = [],
        ?array $sveKljucneReci = null,
        public bool $vrstaJePomenuta = true,
        public array $grupe = [],
    ) {
        $this->sveKljucneReci = $sveKljucneReci ?? $kljucneReci;
    }

    public function jePrazan(): bool
    {
        return $this->vrsta === null && $this->grad === null && $this->kome === null && $this->kljucneReci === [] && $this->grupe === [];
    }

    // Vodiči i organizacije nemaju vrstu ni „kome": kad pitanje traži baš njih („vodič za konkurse"), reči koje su ponavljale
    // vrstu prilike („konkurse") su tema i idu u pretragu; bez reči „vodič" odnosno „organizacija" ostaju reči za prilike.
    /** @return list<string> */
    public function reciZa(string $opstaRec): array
    {
        return (new OpsteReci($this->sveKljucneReci))->sadrzi($opstaRec) ? $this->sveKljucneReci : $this->kljucneReci;
    }
}
