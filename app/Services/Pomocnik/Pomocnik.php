<?php

namespace App\Services\Pomocnik;

use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use Illuminate\Support\Collection;

// Pomoćnik odgovara samo iz baze: model pretvara pitanje u formular, a pretragu i proveru radi kod.
final class Pomocnik
{
    public const NEMAM_PODATAK = 'Nemam podatak.';

    public const NAJVISE_ZNAKOVA_PITANJA = 300;

    public function __construct(private readonly PitanjeUFormular $formular, private readonly PretragaPrilika $pretraga) {}

    /** @throws OllamaNedostupna */
    public function pretrazi(string $pitanje): RezultatPretrage
    {
        $pitanje = mb_substr(trim(Latinica::izCirilice($pitanje)), 0, self::NAJVISE_ZNAKOVA_PITANJA);

        // Prazno pitanje se ne šalje modelu: nema šta da se pita.
        if ($pitanje === '') {
            return new RezultatPretrage(new Formular, new Collection);
        }

        $formular = $this->formular->izPitanja($pitanje);

        return new RezultatPretrage($formular, $this->pretraga->pronadji($formular));
    }
}
