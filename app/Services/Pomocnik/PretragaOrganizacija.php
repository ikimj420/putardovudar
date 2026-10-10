<?php

namespace App\Services\Pomocnik;

use App\Models\Organizacija;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Organizacije se traže samo po prihvaćenim ključnim rečima u nazivu i uslugama, samo kroz Organizacija::javne().
// Grad se ne koristi: „mesto" je slobodan tekst (PITANJA-18-22.md, paket 20, tačka 3). Reč „organizacija" ne sužava pretragu,
// a kad su sve reči „vodič" ili „organizacija", izlistavaju se objavljene organizacije po nazivu.
final class PretragaOrganizacija
{
    public const NAJVISE_ZAPISA = 3;

    public const NAJVISE_U_SPISKU = 5;

    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, Organizacija> */
    public function pronadji(Formular $formular): Collection
    {
        $reci = new OpsteReci($formular->kljucneReci);

        if ($reci->jeSamoOpste()) {
            return $reci->sadrzi(OpsteReci::ORGANIZACIJA) ? Organizacija::javne()->orderBy('naziv')->limit(self::NAJVISE_U_SPISKU)->get() : new Collection;
        }

        $izrazi = $reci->izrazi(OpsteReci::ORGANIZACIJA);

        if ($izrazi === []) {
            return new Collection;
        }

        return Organizacija::javne()->orderBy('naziv')->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (Organizacija $organizacija) => $this->sadrziSveIzraze($organizacija, $izrazi))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
    }

    /** @param  list<string>  $izrazi */
    private function sadrziSveIzraze(Organizacija $organizacija, array $izrazi): bool
    {
        $tekst = PoredjenjeTeksta::normalizuj($organizacija->naziv.' '.implode(' ', $organizacija->usluge ?? []));

        foreach ($izrazi as $izraz) {
            if (! PoredjenjeTeksta::sadrziIzraz($tekst, $izraz)) {
                return false;
            }
        }

        return true;
    }
}
