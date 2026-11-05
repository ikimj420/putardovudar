<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Support\PoredjenjeTeksta;
use Illuminate\Database\Eloquent\Builder;
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
        $rezultat = $this->trazi($formular);

        // Vrsta koju je model samo pogodio („radionica" kao „obuka") ne sme da sakrije priliku: bez nje se traži tek kad
        // sa njom nema ničega (a bez ijednog drugog uslova ne traži se ništa). Izrečena vrsta („stipendija u Kragujevcu") je uvek tvrda.
        if ($rezultat->isEmpty() && $formular->vrsta !== null && ! $formular->vrstaJePomenuta) {
            return $this->trazi(new Formular(null, $formular->grad, $formular->kome, $formular->kljucneReci, grupe: $formular->grupe));
        }

        return $rezultat;
    }

    /** @return Collection<int, Prilika> */
    private function trazi(Formular $formular): Collection
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

        // Grupa iz pitanja se poredi sa grupama prilike (bar jedna od traženih); prilika bez grupa ne ulazi.
        if ($formular->grupe !== []) {
            $upit->where(function (Builder $grupe) use ($formular) {
                foreach ($formular->grupe as $grupa) {
                    $grupe->orWhereJsonContains('grupe', $grupa->value);
                }
            });
        }

        // Uz vrstu, izraz koji je samo njena reč („kurs" uz obuku) ne traži se u tekstu, isto kao „obuka". Bez vrste (rezervna
        // pretraga iz paketa 30) ostaje reč, pa „kurs u Novom Sadu" ne vraća svaku priliku iz tog grada (paket 102/T2).
        $izrazi = array_filter(array_filter([$formular->kome, ...$formular->kljucneReci]), fn (string $izraz) => ! ($formular->vrsta?->jeSamoRecVrste($izraz) ?? false));

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
