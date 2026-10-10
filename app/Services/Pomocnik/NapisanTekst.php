<?php

namespace App\Services\Pomocnik;

final readonly class NapisanTekst
{
    /**
     * @param  bool  $napisaoModel  tekst je odgovor modela koji je prošao proveru; inače je to šablon sklopljen sa odgovorima
     * @param  string|null  $razlog  zašto je umesto teksta modela stao sklopljen šablon, ili null kad je tekst modela prošao proveru
     */
    public function __construct(public string $tekst, public bool $napisaoModel, public ?string $razlog = null) {}
}
