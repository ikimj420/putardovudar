<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\PrikazPrilike;
use Illuminate\Support\Collection;

// Drugi posao pomoćnika: model prepričava pronađene zapise, i ništa drugo.
final class PisanjeOdgovora
{
    private const UPUTSTVO = <<<'TEXT'
Odgovori na pitanje u najviše tri rečenice, na srpskom jeziku latinicom, samo iz priloženih prilika.
Ne navodi linkove i ne dodaj ništa iz sopstvenog znanja. Datum piši samo ako piše u prilikama.
Ako prilike ne odgovaraju pitanju, reci samo da nemaš podatak.
TEXT;

    public function __construct(private readonly Ollama $ollama) {}

    /**
     * @param  Collection<int, Prilika>  $zapisi
     *
     * @throws OllamaNedostupna
     */
    public function napisi(string $pitanje, Collection $zapisi): string
    {
        return $this->ollama->odgovoriTekstom(self::UPUTSTVO, "Pitanje: {$pitanje}\n\nPrilike:\n".self::kontekst($zapisi));
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
