<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\PrikazPrilike;
use Illuminate\Support\Collection;

// Drugi posao pomoćnika: model prepričava pronađene zapise, i ništa drugo.
final class PisanjeOdgovora
{
    private const UPUTSTVO = <<<'TEXT'
Odgovori na pitanje u najviše tri rečenice, na srpskom jeziku latinicom, samo iz priloženih prilika i vodiča.
Ne navodi linkove i ne dodaj ništa iz sopstvenog znanja. Datum piši samo ako piše u prilikama ili vodičima.
Ako prilike i vodiči ne odgovaraju pitanju, reci samo da nemaš podatak.
TEXT;

    public function __construct(private readonly Ollama $ollama) {}

    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     *
     * @throws OllamaNedostupna
     */
    public function napisi(string $pitanje, Collection $zapisi, Collection $vodici, ?int $vremeCekanja = null): string
    {
        $tekst = "Pitanje: {$pitanje}";

        if ($zapisi->isNotEmpty()) {
            $tekst .= "\n\nPrilike:\n".self::kontekst($zapisi);
        }

        if ($vodici->isNotEmpty()) {
            // Modelu idu samo naslov i kratak opis; tekst, koraci i beleška ostaju u bazi.
            $tekst .= "\n\nVodiči:\n".$vodici->map(fn (Vodic $vodic) => 'Naslov: '.$vodic->naslov.'; Kratak opis: '.$vodic->kratak_opis)->implode("\n");
        }

        // Model ume da pređe na ćirilicu ili da je pomeša sa latinicom; sajt piše samo latinicom.
        return Latinica::izCirilice($this->ollama->odgovoriTekstom(self::UPUTSTVO, $tekst, $vremeCekanja));
    }

    /** @param  Collection<int, Prilika>  $zapisi */
    public static function kontekst(Collection $zapisi): string
    {
        return $zapisi->map(fn (Prilika $prilika) => implode('; ', array_filter([
            'Naslov: '.$prilika->naslov,
            'Vrsta: '.$prilika->vrsta->getLabel(),
            PrikazPrilike::mesto($prilika) ? 'Mesto: '.PrikazPrilike::mesto($prilika) : null,
            'Rok: '.PrikazPrilike::rok($prilika),
            'Kratak opis: '.$prilika->kratak_opis,
        ])))->implode("\n");
    }
}
