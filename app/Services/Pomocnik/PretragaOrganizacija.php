<?php

namespace App\Services\Pomocnik;

use App\Models\Organizacija;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Organizacije se traže samo po prihvaćenim ključnim rečima u nazivu i uslugama, samo kroz Organizacija::javne(). Reč „organizacija"
// ne sužava pretragu, a kad su sve reči „vodič" ili „organizacija", izlistavaju se objavljene organizacije po nazivu.
// Grad sužava: organizacija ulazi kad je online ili kad njeno „mesto" (slobodan tekst) sadrži grad, u bilo kom padežu.
final class PretragaOrganizacija
{
    public const NAJVISE_ZAPISA = 3;

    public const NAJVISE_U_SPISKU = 5;

    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, Organizacija> */
    public function pronadji(Formular $formular): Collection
    {
        $reci = new OpsteReci($formular->reciZa(OpsteReci::ORGANIZACIJA));

        if ($reci->jeSamoOpste()) {
            if (! $reci->sadrzi(OpsteReci::ORGANIZACIJA)) {
                return new Collection;
            }

            return $this->kandidati($formular)->take(self::NAJVISE_U_SPISKU)->values();
        }

        $izrazi = $reci->izrazi(OpsteReci::ORGANIZACIJA);

        if ($izrazi === []) {
            return new Collection;
        }

        return $this->kandidati($formular)
            ->filter(fn (Organizacija $organizacija) => $this->sadrziSveIzraze($organizacija, $izrazi))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
    }

    /** @return Collection<int, Organizacija> */
    private function kandidati(Formular $formular): Collection
    {
        return Organizacija::javne()->orderBy('naziv')->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (Organizacija $organizacija) => $formular->grad === null || $this->jeUGradu($organizacija, $formular->grad))
            ->values();
    }

    private function jeUGradu(Organizacija $organizacija, string $grad): bool
    {
        if ($organizacija->online) {
            return true;
        }

        $trazen = PoredjenjeTeksta::normalizuj($grad);

        return $trazen !== '' && PoredjenjeTeksta::sadrziIzraz(PoredjenjeTeksta::normalizuj((string) $organizacija->mesto), $trazen);
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
