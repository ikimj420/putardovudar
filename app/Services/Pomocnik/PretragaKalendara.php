<?php

namespace App\Services\Pomocnik;

use App\Enums\Grupa;
use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Kalendar se traži samo kroz KalendarStavka::javne() i samo po ključnim rečima u nazivu, ko daje i napomeni („Dositeja"): bez reči
// pitanje nije o nekoj stavci, pa se kalendar ne izlistava. Grupa iz pitanja sužava samo stavke koje grupe imaju.
final class PretragaKalendara
{
    public const NAJVISE_ZAPISA = 3;

    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, KalendarStavka> */
    public function pronadji(Formular $formular): Collection
    {
        // Iz svake fraze ispadaju reči koje samo kažu grupu ili vrstu (stavka kalendara nema vrstu, a grupe se gledaju posebno); ostale
        // ne gube ni kad liče na vrstu („ŠTIT" počinje kao „stipendija"), pa „stipendija Dositeja" traži „dositeja".
        $izrazi = $this->bezReciVrsteIGrupe($formular->sveKljucneReci);

        // Sama reč „kome" (mlade) ne otvara kalendar, ali kad ga otvori druga reč, i ona mora da piše u stavci: bez nje bi
        // „program" iz „program za roditelje" pogodio svaku stavku sa tom rečju.
        if ($izrazi === []) {
            return new Collection;
        }

        $izrazi = [...$this->bezReciVrsteIGrupe($formular->kome === null ? [] : [$formular->kome]), ...$izrazi];

        return KalendarStavka::javne()->orderBy('otvoreno_do', 'desc')->orderBy('id')->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (KalendarStavka $stavka) => $this->sadrziSveIzraze($stavka, $izrazi) && $this->jeZaGrupu($stavka, $formular))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
    }

    /**
     * @param  list<string>  $fraze
     * @return list<string>
     */
    private function bezReciVrsteIGrupe(array $fraze): array
    {
        $izrazi = [];

        foreach ($fraze as $fraza) {
            foreach (Grupa::bezReciGrupe($fraza) as $deo) {
                $niz = [];

                foreach ([...explode(' ', $deo), null] as $rec) {
                    if ($rec !== null && ! $this->jeRecVrste($rec)) {
                        $niz[] = $rec;

                        continue;
                    }

                    $izraz = Grupa::bezPredlogaNaKrajevima(implode(' ', $niz));
                    $izrazi = $izraz === '' ? $izrazi : [...$izrazi, $izraz];
                    $niz = [];
                }
            }
        }

        return $izrazi;
    }

    private function jeRecVrste(string $rec): bool
    {
        foreach (VrstaPrilike::cases() as $vrsta) {
            if ($vrsta->potkrepljenaTekstom($rec)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $izrazi */
    private function sadrziSveIzraze(KalendarStavka $stavka, array $izrazi): bool
    {
        $tekst = PoredjenjeTeksta::normalizuj($stavka->naziv.' '.$stavka->ko_daje.' '.$stavka->napomena);

        foreach ($izrazi as $izraz) {
            if (! PoredjenjeTeksta::sadrziIzraz($tekst, PoredjenjeTeksta::normalizuj($izraz))) {
                return false;
            }
        }

        return true;
    }

    // Stavka bez grupa važi za svakoga; sa grupama mora da bude za bar jednu traženu.
    private function jeZaGrupu(KalendarStavka $stavka, Formular $formular): bool
    {
        return $formular->grupe === [] || $stavka->izabraneGrupe() === [] || array_intersect(array_map(fn (Grupa $grupa) => $grupa->value, $formular->grupe), $stavka->grupe ?? []) !== [];
    }
}
