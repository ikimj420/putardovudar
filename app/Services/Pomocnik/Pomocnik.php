<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\PrikazPrilike;
use Illuminate\Support\Collection;

// Pomoćnik odgovara samo iz baze: model pretvara pitanje u formular, a pretragu i proveru radi kod.
final class Pomocnik
{
    public const NEMAM_PODATAK = 'Nemam podatak.';

    public const SABLON_NASLOV = 'Našao sam sledeće prilike:';

    public const NAJVISE_ZNAKOVA_PITANJA = 300;

    public function __construct(
        private readonly PitanjeUFormular $formular,
        private readonly PretragaPrilika $pretraga,
        private readonly PisanjeOdgovora $pisanje,
        private readonly ProveraOdgovora $provera,
    ) {}

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

    /**
     * Pitanje → formular → pretraga → odgovor. Nema zapisa: „nemam podatak" i model se ne zove drugi put.
     * Ako model u odgovoru ne prođe proveru (ili ne radi), umesto njegovog odgovora stoji šablon iz zapisa.
     *
     * @throws OllamaNedostupna samo kad ne radi prvi poziv (formular); tada nema šta da se kaže
     */
    public function odgovori(string $pitanje): OdgovorPomocnika
    {
        $rezultat = $this->pretrazi($pitanje);

        if ($rezultat->nemaPodatak()) {
            return new OdgovorPomocnika(self::NEMAM_PODATAK, [], true);
        }

        $izvori = $rezultat->zapisi->map(fn (Prilika $prilika) => $this->izvor($prilika))->all();

        try {
            $tekst = $this->pisanje->napisi($pitanje, $rezultat->zapisi);
        } catch (OllamaNedostupna) {
            return new OdgovorPomocnika($this->sablon($rezultat->zapisi), $izvori, false, 'model ne odgovara');
        }

        $razlog = $this->provera->razlogOdbijanja($tekst, $rezultat->zapisi);

        return $razlog === null
            ? new OdgovorPomocnika($tekst, $izvori, false)
            : new OdgovorPomocnika($this->sablon($rezultat->zapisi), $izvori, false, $razlog);
    }

    /** @param  Collection<int, Prilika>  $zapisi */
    private function sablon(Collection $zapisi): string
    {
        return self::SABLON_NASLOV."\n".$zapisi->map(fn (Prilika $prilika) => '• '.$prilika->naslov.' — '.PrikazPrilike::rok($prilika))->implode("\n");
    }

    private function izvor(Prilika $prilika): Izvor
    {
        return new Izvor(
            $prilika->naslov,
            PrikazPrilike::rok($prilika),
            route('prilike.show', $prilika->slug),
            PrikazPrilike::nazivIzvora($prilika),
            PrikazPrilike::linkIzvora($prilika),
        );
    }
}
