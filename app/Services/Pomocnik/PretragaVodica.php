<?php

namespace App\Services\Pomocnik;

use App\Models\Vodic;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Vodiči se traže samo po prihvaćenim ključnim rečima (naslov i kratak opis) i samo kroz Vodic::javni().
final class PretragaVodica
{
    public const NAJVISE_ZAPISA = 3;

    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, Vodic> */
    public function pronadji(Formular $formular): Collection
    {
        if ($formular->kljucneReci === []) {
            return new Collection;
        }

        return Vodic::javni()->orderBy('naslov')->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (Vodic $vodic) => $this->sadrziSveReci($vodic, $formular->kljucneReci))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
    }

    /** @param  list<string>  $reci */
    private function sadrziSveReci(Vodic $vodic, array $reci): bool
    {
        $tekst = PoredjenjeTeksta::normalizuj($vodic->naslov.' '.$vodic->kratak_opis);

        foreach ($reci as $rec) {
            if (! PoredjenjeTeksta::sadrziIzraz($tekst, PoredjenjeTeksta::normalizuj($rec))) {
                return false;
            }
        }

        return true;
    }
}
