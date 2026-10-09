<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Pretragu radi kod, samo kroz Prilika::javne() — isteklo i nejavno nikad ne ulazi.
final class PretragaPrilika
{
    public const NAJVISE_ZAPISA = 5;

    // Gornja granica pre filtriranja po tekstu, da pretraga ne učitava celu bazu.
    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, Prilika> */
    public function pronadji(Formular $formular): Collection
    {
        if ($formular->jePrazan()) {
            return new Collection;
        }

        $upit = Prilika::javne()
            ->orderByRaw('rok IS NULL OR rok_stalno_otvoren')
            ->orderBy('rok')
            ->orderBy('id');

        if ($formular->vrsta !== null) {
            $upit->where('vrsta', $formular->vrsta);
        }

        if ($formular->grad !== null) {
            $upit->where('mesto', $formular->grad);
        }

        $izrazi = array_filter([$formular->kome, ...$formular->kljucneReci]);

        return $upit->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (Prilika $prilika) => $this->sadrziSveIzraze($prilika, $izrazi))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
    }

    /** @param  array<int, string>  $izrazi */
    private function sadrziSveIzraze(Prilika $prilika, array $izrazi): bool
    {
        $tekst = PoredjenjeTeksta::normalizuj($prilika->naslov.' '.$prilika->kratak_opis.' '.$prilika->opis);

        foreach ($izrazi as $izraz) {
            if (! PoredjenjeTeksta::sadrziIzraz($tekst, PoredjenjeTeksta::normalizuj($izraz))) {
                return false;
            }
        }

        return true;
    }
}
