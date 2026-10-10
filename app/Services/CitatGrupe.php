<?php

namespace App\Services;

use App\Enums\Grupa;
use App\Support\PoredjenjeTeksta;

// Kad citat koji je model naveo za grupu ne piše doslovno na strani, kod sam traži na strani rečenicu sa rečju te grupe (isti rečnik kao
// za proveru citata). Citat je doslovan deo strane oko te reči, pa se proverava isto kao citat modela.
final class CitatGrupe
{
    private const NAJVISE_ZNAKOVA = 300;

    private const NAJMANJE_RECI = 4;

    private const NAJVISE_RECI_U_RECENICI = 250;

    // Reči oko pronađene reči grupe koje ulaze u citat (ne prelaze granicu rečenice).
    private const RECI_PRE = 10;

    private const RECI_POSLE = 12;

    // Najduži izraz iz rečnika ima do šest reči („učenici … osnovne"); traži se najkraći deo teksta koji grupu potkrepljuje.
    private const NAJVISE_RECI_U_IZRAZU = 8;

    // Reči koje kažu kome je nešto namenjeno; citat sa njima ima prednost nad prvim u kome se grupa samo pominje.
    private const NAMENA = '/\b(?:namenjen\w*|za|mogu|moze|pravo|uslov\w*|prijav\w*|konkur\w*|poziv\w*|otvoren\w*)\b/';

    public function naStrani(Grupa $grupa, string $tekst): ?string
    {
        $prvi = null;

        foreach ($this->recenice($tekst) as $recenica) {
            $citat = $this->okoReci($grupa, $recenica);

            if ($citat === null) {
                continue;
            }

            if (preg_match(self::NAMENA, PoredjenjeTeksta::normalizuj($citat)) === 1) {
                return $citat;
            }

            $prvi ??= $citat;
        }

        return $prvi;
    }

    /** @return list<string> delovi teksta koji liče na rečenicu: završavaju se tačkom, uzvičnikom ili upitnikom i nisu spisak naslova */
    private function recenice(string $tekst): array
    {
        $recenice = [];

        foreach (preg_split('/(?<=[.!?])\s+(?=[\p{Lu}„"“(\d])/u', $tekst) ?: [] as $deo) {
            $deo = trim($deo);
            preg_match_all('/\p{L}[\p{L}\p{N}\'’-]*/u', $deo, $reci);
            $broj = count($reci[0]);
            $velika = count(array_filter($reci[0], fn (string $rec) => preg_match('/^\p{Lu}/u', $rec) === 1));

            // Spisak naslova (meni, oznake) ima gotovo sve reči velikim slovom; rečenica ima najviše svaku drugu.
            if (preg_match('/[.!?]$/u', $deo) === 1 && $broj >= self::NAJMANJE_RECI && $broj <= self::NAJVISE_RECI_U_RECENICI && $velika * 2 <= $broj + 1) {
                $recenice[] = $deo;
            }
        }

        return $recenice;
    }

    // Cela rečenica ako staje u NAJVISE_ZNAKOVA; inače deo oko najkraćeg niza reči koji potkrepljuje grupu: najviše RECI_PRE reči pre i RECI_POSLE
    // posle. Levo se staje posle natpisa sa dvotačkom i pre niza reči velikim slovom (meni, naslovi); najviše NAJVISE_ZNAKOVA.
    private function okoReci(Grupa $grupa, string $recenica): ?string
    {
        preg_match_all('/\S+/u', $recenica, $reci, PREG_OFFSET_CAPTURE);
        $reci = $reci[0];
        $broj = count($reci);

        for ($duzina = 1; $duzina <= self::NAJVISE_RECI_U_IZRAZU; $duzina++) {
            for ($i = 0; $i + $duzina <= $broj; $i++) {
                if (! $grupa->potkrepljuje($this->spoji($recenica, $reci, $i, $i + $duzina - 1))) {
                    continue;
                }

                if (mb_strlen($recenica) <= self::NAJVISE_ZNAKOVA) {
                    return $recenica;
                }

                $od = $i;

                while ($od > 0 && $i - $od < self::RECI_PRE && ! str_ends_with($reci[$od - 1][0], ':') && ! ($this->velikoSlovo($reci[$od][0]) && $this->velikoSlovo($reci[$od - 1][0]))) {
                    $od--;
                }

                $do = min($broj - 1, $i + $duzina - 1 + self::RECI_POSLE);

                while ($do > $i + $duzina - 1 && mb_strlen($this->spoji($recenica, $reci, $od, $do)) > self::NAJVISE_ZNAKOVA) {
                    $do--;
                }

                while ($od < $i && mb_strlen($this->spoji($recenica, $reci, $od, $do)) > self::NAJVISE_ZNAKOVA) {
                    $od++;
                }

                return $this->spoji($recenica, $reci, $od, $do);
            }
        }

        return null;
    }

    private function velikoSlovo(string $rec): bool
    {
        return preg_match('/^\p{Lu}/u', $rec) === 1;
    }

    /** @param  list<array{0: string, 1: int}>  $reci */
    private function spoji(string $recenica, array $reci, int $od, int $do): string
    {
        $pocetak = $reci[$od][1];

        return substr($recenica, $pocetak, $reci[$do][1] + strlen($reci[$do][0]) - $pocetak);
    }
}
