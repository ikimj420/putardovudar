<?php

namespace App\Services\Pomocnik;

use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Claude\Claude;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\PrikazKalendara;
use App\Support\PrikazPrilike;
use Illuminate\Support\Collection;

// Drugi posao pomoćnika: model prepričava pronađene zapise, i ništa drugo.
final class PisanjeOdgovora
{
    private const UPUTSTVO_DUZINA = <<<'TEXT'
Odgovori na pitanje u jednoj ili dve kratke rečenice, na srpskom jeziku latinicom, samo iz priloženih prilika, vodiča i organizacija.
Reci šta si našao i za koga je. Ne prepričavaj rok, mesto ni uslove: oni su u karticama ispod odgovora.
Piši toplo i jednostavno, na ti, kao drug koji pomaže, kratkim rečenicama.
TEXT;

    // Izmereno na pravom modelu: s ovim redom pravilno piše „Našao sam" i ne nabraja; uz kalendar se ne dodaje, jer bi „Našao sam da se otvara" rekao budućnost.
    private const UPUTSTVO_NASAO = 'Počni sa rečima „Našao sam“ i reci o čemu su prilike. Ne piši koliko ih ima.';

    private const UPUTSTVO_PRAVILA = <<<'TEXT'
Ne navodi linkove i ne dodaj ništa iz sopstvenog znanja. Datum piši samo ako piše u prilikama, vodičima ili organizacijama.
Ako prilike, vodiči i organizacije ne odgovaraju pitanju, reci samo da nemaš podatak.
TEXT;

    // Dodaje se samo kad je kalendar u kontekstu, da pitanja bez kalendara dobiju isto uputstvo kao ranije (izmereno na pravom modelu).
    private const UPUTSTVO_KALENDAR = <<<'TEXT'
Kalendar kaže samo šta je bilo prošli put: ne predviđaj kada će se nešto otvoriti i ne piši nijedan datum koji nije u kalendaru.
Pitanje „kada se otvara" znači „kada je bilo otvoreno prošli put": ako kalendar ima tu stavku, odgovori samo od kog do kog datuma je bila otvorena prošli put, i ništa o budućnosti.
TEXT;

    public function __construct(private readonly Claude $model) {}

    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     * @param  Collection<int, Organizacija>  $organizacije
     * @param  Collection<int, KalendarStavka>  $kalendar
     *
     * @throws OllamaNedostupna
     */
    public function napisi(string $pitanje, Collection $zapisi, Collection $vodici, Collection $organizacije, Collection $kalendar, ?int $vremeCekanja = null): string
    {
        $tekst = "Pitanje: {$pitanje}";

        if ($zapisi->isNotEmpty()) {
            $tekst .= "\n\nPrilike:\n".self::kontekst($zapisi);
        }

        if ($vodici->isNotEmpty()) {
            // Modelu idu samo naslov i kratak opis; tekst, koraci i beleška ostaju u bazi.
            $tekst .= "\n\nVodiči:\n".$vodici->map(fn (Vodic $vodic) => 'Naslov: '.$vodic->naslov.'; Kratak opis: '.$vodic->kratak_opis)->implode("\n");
        }

        if ($organizacije->isNotEmpty()) {
            // Modelu idu samo naziv, vrsta, kratak opis i usluge; opis, mesto, telefon, sajt i beleška ostaju u bazi.
            $tekst .= "\n\nOrganizacije:\n".$organizacije->map(fn (Organizacija $organizacija) => implode('; ', array_filter([
                'Naziv: '.$organizacija->naziv,
                'Vrsta: '.$organizacija->vrsta->getLabel(),
                'Kratak opis: '.$organizacija->kratak_opis,
                $organizacija->usluge ? 'Usluge: '.implode(', ', $organizacija->usluge) : null,
            ])))->implode("\n");
        }

        if ($kalendar->isNotEmpty()) {
            // Modelu idu naziv, ko daje, grupe, „Prošli put" i napomena; link izvora ide uz odgovor iz zapisa.
            $tekst .= "\n\nKalendar:\n".$kalendar->map(fn (KalendarStavka $stavka) => implode('; ', array_filter([
                'Naziv: '.$stavka->naziv,
                filled($stavka->ko_daje) ? 'Ko daje: '.$stavka->ko_daje : null,
                PrikazPrilike::nazivi($stavka->izabraneGrupe()) ? PrikazPrilike::KOME.': '.PrikazPrilike::nazivi($stavka->izabraneGrupe()) : null,
                PrikazKalendara::prosliPut($stavka),
                filled($stavka->napomena) ? 'Napomena: '.$stavka->napomena : null,
            ])))->implode("\n");
        }

        // Model ume da pređe na ćirilicu ili da je pomeša sa latinicom; sajt piše samo latinicom. Dužinu odgovora kod ograničava, jer model uputstvo ne drži.
        return KratakOdgovor::skrati(Latinica::izCirilice($this->model->odgovoriTekstom($this->uputstvo($kalendar->isNotEmpty()), $tekst, $vremeCekanja)));
    }

    private function uputstvo(bool $imaKalendar): string
    {
        return implode("\n", $imaKalendar
            ? [self::UPUTSTVO_DUZINA, self::UPUTSTVO_PRAVILA, self::UPUTSTVO_KALENDAR]
            : [self::UPUTSTVO_DUZINA, self::UPUTSTVO_NASAO, self::UPUTSTVO_PRAVILA]);
    }

    /** @param  Collection<int, Prilika>  $zapisi */
    public static function kontekst(Collection $zapisi): string
    {
        return $zapisi->map(fn (Prilika $prilika) => implode('; ', array_filter([
            'Naslov: '.$prilika->naslov,
            'Vrsta: '.$prilika->vrsta->getLabel(),
            PrikazPrilike::mesto($prilika) ? 'Mesto: '.PrikazPrilike::mesto($prilika) : null,
            PrikazPrilike::grupe($prilika) ? PrikazPrilike::KOME.': '.PrikazPrilike::grupe($prilika) : null,
            'Rok: '.PrikazPrilike::rok($prilika),
            'Kratak opis: '.$prilika->kratak_opis,
        ])))->implode("\n");
    }
}
