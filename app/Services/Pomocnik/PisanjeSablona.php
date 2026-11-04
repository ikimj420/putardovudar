<?php

namespace App\Services\Pomocnik;

use App\Enums\Rod;
use App\Models\Sablon;
use App\Services\Claude\Claude;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\RodIzImena;
use App\Support\SklapanjeSablona;
use App\Support\ZapisGreske;
use Throwable;

// „Napiši moj": model piše tekst po šablonu iz onoga što je čovek upisao, a kod proverava šta je napisao. Šta ne prođe proveru
// (ili model ne radi), zamenjuje šablon sklopljen sa odgovorima. Ništa se ne čuva.
final class PisanjeSablona
{
    private const UPUTSTVO = <<<'TEXT'
Napiši tekst po šablonu, na srpskom jeziku latinicom. Vrati samo taj tekst.
Drži se oblika šablona.
Koristi samo ono što je čovek upisao. Ne dodaj iskustvo, škole, imena, datume, brojeve, adrese ni linkove kojih nema u upisanom.
Ono što nije upisano izostavi.
TEXT;

    // Model rod dobija izričito, sa primerima oblika.
    private const ROD = [
        'muski' => 'Rod autora je muški: piši u muškom rodu (radio sam, video sam, zadovoljan sam).',
        'zenski' => 'Rod autora je ženski: piši u ženskom rodu (radila sam, videla sam, zadovoljna sam).',
    ];

    public function __construct(private readonly Claude $model, private readonly ProveraNapisanogTeksta $provera) {}

    /**
     * @param  array<string, string>  $unosi  ključ polja → šta je čovek upisao
     * @param  Rod|null  $rod  rod u kom se piše; bez njega model ne dobija uputstvo o rodu
     */
    public function napisi(Sablon $sablon, array $unosi, ?Rod $rod = null): NapisanTekst
    {
        $slozen = SklapanjeSablona::sklopi($sablon, $unosi, $rod);

        try {
            $tekst = Latinica::izCirilice($this->model->odgovoriTekstom(self::UPUTSTVO, $this->poruka($sablon, $unosi, $rod), (int) config('pomocnik.ukupno_za_pitanje')));
            $ime = in_array(RodIzImena::POLJE, $sablon->kljucevi(), true) ? ($unosi[RodIzImena::POLJE] ?? '') : null;
            $razlog = $this->provera->razlogOdbijanja($tekst, $this->dozvoljeno($sablon, $unosi, $rod), $ime, $this->komePrazno($sablon, $unosi) ? $ime : null);
        } catch (OllamaNedostupna) {
            return new NapisanTekst($slozen, false, 'model ne odgovara');
        } catch (Throwable $izuzetak) {
            // Bilo koja druga greška ne sme do rukovaoca izuzecima, koji u log piše poruku i trag poziva sa onim što je čovek upisao (paket 64).
            ZapisGreske::vrsta('Napiši moj', $izuzetak);

            return new NapisanTekst($slozen, false, 'neočekivana greška');
        }

        return $razlog === null ? new NapisanTekst($tekst, true) : new NapisanTekst($slozen, false, $razlog);
    }

    // Šablon ima polje „kome", a čovek ga je ostavio prazno: pozdrav ne sme da bude ime autora (paket 70).
    /** @param  array<string, string>  $unosi */
    private function komePrazno(Sablon $sablon, array $unosi): bool
    {
        return in_array('kome', $sablon->kljucevi(), true) && trim($unosi['kome'] ?? '') === '';
    }

    /** @param  array<string, string>  $unosi */
    private function poruka(Sablon $sablon, array $unosi, ?Rod $rod): string
    {
        // Primer vodiča se modelu ne šalje (paket 67): prepisivao je rečenice koje čovek nije upisao. Model dobija samo šablon i upisano.
        // Šablon bez redova praznih opcionih polja, a podrazumevana reč uz prazno polje ide među upisano (paket 70).
        $poruka = "Šablon:\n".SklapanjeSablona::tekstSablona($sablon, $unosi, $rod);
        $unosi = SklapanjeSablona::unosiSaPodrazumevanim($sablon, $unosi);

        if ($rod !== null) {
            $poruka .= "\n\n".self::ROD[$rod->value];
        }

        $upisano = [];

        foreach ((array) $sablon->polja as $polje) {
            $kljuc = is_array($polje) ? ($polje['kljuc'] ?? null) : null;
            $vrednost = is_string($kljuc) ? trim($unosi[$kljuc] ?? '') : '';

            if ($vrednost !== '') {
                $upisano[] = '{'.$kljuc.'} ('.($polje['oznaka'] ?? $kljuc).'): '.$vrednost;
            }
        }

        return $poruka."\n\nUpisano:\n".implode("\n", $upisano);
    }

    // Model sme da ponovi ono što je čovek upisao i stalni tekst šablona (tekst bez mesta za polja), i ništa drugo.
    /** @param  array<string, string>  $unosi */
    private function dozvoljeno(Sablon $sablon, array $unosi, ?Rod $rod): string
    {
        return implode("\n", SklapanjeSablona::unosiSaPodrazumevanim($sablon, $unosi))."\n".preg_replace('/\{[a-z][a-z0-9_]*\}/', ' ', SklapanjeSablona::uRodu((string) $sablon->tekst, $rod));
    }
}
