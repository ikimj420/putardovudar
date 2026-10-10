<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\PrikazPrilike;
use Illuminate\Support\Collection;

// Pomoćnik odgovara samo iz baze: model pretvara pitanje u formular, a pretragu i proveru radi kod.
final class Pomocnik
{
    public const NEMAM_PODATAK = 'Nemam podatak.';

    public const SABLON_NASLOV = 'Našao sam sledeće prilike:';

    public const SABLON_NASLOV_VODICI = 'Našao sam sledeće vodiče:';

    public const NAJVISE_ZNAKOVA_PITANJA = 300;

    public function __construct(
        private readonly PitanjeUFormular $formular,
        private readonly PretragaPrilika $pretraga,
        private readonly PretragaVodica $pretragaVodica,
        private readonly PisanjeOdgovora $pisanje,
        private readonly ProveraOdgovora $provera,
    ) {}

    /** @throws OllamaNedostupna */
    public function pretrazi(string $pitanje, ?int $vremeCekanja = null): RezultatPretrage
    {
        $pitanje = mb_substr(trim(Latinica::izCirilice($pitanje)), 0, self::NAJVISE_ZNAKOVA_PITANJA);

        // Prazno pitanje se ne šalje modelu: nema šta da se pita.
        if ($pitanje === '') {
            return new RezultatPretrage(new Formular, new Collection, new Collection);
        }

        $formular = $this->formular->izPitanja($pitanje, $vremeCekanja);

        return new RezultatPretrage($formular, $this->pretraga->pronadji($formular), $this->pretragaVodica->pronadji($formular));
    }

    /**
     * Pitanje → formular → pretraga → odgovor. Nema zapisa: „nemam podatak" i model se ne zove drugi put.
     * Ako model u odgovoru ne prođe proveru (ili ne radi, ili nema dovoljno vremena), umesto njegovog odgovora stoji šablon iz zapisa.
     * Oba poziva zajedno staju u `pomocnik.ukupno_za_pitanje`: prvi dobija polovinu, drugi ostatak.
     *
     * @throws OllamaNedostupna samo kad ne radi prvi poziv (formular); tada nema šta da se kaže
     */
    public function odgovori(string $pitanje): OdgovorPomocnika
    {
        $ukupno = (int) config('pomocnik.ukupno_za_pitanje');
        $pocetak = now();
        $rezultat = $this->pretrazi($pitanje, intdiv($ukupno, 2));

        if ($rezultat->nemaPodatak()) {
            return new OdgovorPomocnika(self::NEMAM_PODATAK, [], true);
        }

        $izvori = [
            ...$rezultat->zapisi->map(fn (Prilika $prilika) => $this->izvor($prilika))->all(),
            ...$rezultat->vodici->map(fn (Vodic $vodic) => $this->izvorVodica($vodic))->all(),
        ];

        $ostalo = $ukupno - (int) ceil($pocetak->diffInSeconds(now()));

        if ($ostalo < (int) config('pomocnik.najmanje_za_odgovor')) {
            return new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, 'nema vremena');
        }

        try {
            $tekst = $this->pisanje->napisi($pitanje, $rezultat->zapisi, $rezultat->vodici, $ostalo);
        } catch (OllamaNedostupna) {
            return new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, 'model ne odgovara');
        }

        $razlog = $this->provera->razlogOdbijanja($tekst, $rezultat->zapisi, $rezultat->vodici);

        return $razlog === null
            ? new OdgovorPomocnika($tekst, $izvori, false)
            : new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, $razlog);
    }

    private function sablon(RezultatPretrage $rezultat): string
    {
        $blokovi = [];

        if ($rezultat->zapisi->isNotEmpty()) {
            $blokovi[] = self::SABLON_NASLOV."\n".$rezultat->zapisi->map(fn (Prilika $prilika) => '• '.$prilika->naslov.' — '.PrikazPrilike::rok($prilika))->implode("\n");
        }

        if ($rezultat->vodici->isNotEmpty()) {
            $blokovi[] = self::SABLON_NASLOV_VODICI."\n".$rezultat->vodici->map(fn (Vodic $vodic) => '• '.$vodic->naslov)->implode("\n");
        }

        return implode("\n\n", $blokovi);
    }

    private function izvorVodica(Vodic $vodic): Izvor
    {
        return new Izvor($vodic->naslov, null, route('vodici.show', $vodic->slug), null, null, true);
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
