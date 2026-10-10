<?php

namespace App\Services\Pomocnik;

use App\Models\Sablon;
use App\Support\PoredjenjeTeksta;

// Traženje da se nešto napiše i naziv šablona u pitanju prepoznaje kod, ne model. Nudi se samo objavljen šablon (Sablon::javni()).
final class PrepoznavanjeSablona
{
    // Traženje je zapovedni način i drugo lice („napiši mi molbu", „možeš li da mi napišeš"). „Kako da napišem" i „kako napisati" pitaju
    // kako se piše, to je pitanje za vodiče, a „napisano" i „napisao" nisu traženje.
    private const GLAGOLI = '/\b(?:napis(?:i|ite|es|ete)|sastav(?:i|ite|is))\b/';

    public function jeTrazenjePisanja(string $pitanje): bool
    {
        return preg_match(self::GLAGOLI, PoredjenjeTeksta::normalizuj($pitanje)) === 1;
    }

    /** @return list<PonudaSablona> objavljeni šabloni čiji su svi naziv-reči u pitanju */
    public function ponude(string $pitanje): array
    {
        $reci = array_values(array_filter(explode(' ', PoredjenjeTeksta::normalizuj($pitanje))));
        $ponude = [];

        foreach (Sablon::javni()->orderBy('naslov')->get() as $sablon) {
            if ($this->nazivJeUPitanju($sablon->naslov, $reci)) {
                $ponude[] = new PonudaSablona($sablon->naslov, route('pomocnik.napisi.forma', $sablon->slug));
            }
        }

        return $ponude;
    }

    // Svaka reč naziva (osim predloga od jednog ili dva slova, osim kad su sve takve) mora da stoji u pitanju, u bilo kom padežu.
    /** @param  list<string>  $reci */
    private function nazivJeUPitanju(string $naziv, array $reci): bool
    {
        $nazivReci = array_values(array_filter(explode(' ', PoredjenjeTeksta::normalizuj($naziv))));
        $vazne = array_values(array_filter($nazivReci, fn (string $rec) => strlen($rec) >= 3)) ?: $nazivReci;

        if ($vazne === []) {
            return false;
        }

        foreach ($vazne as $nazivRec) {
            if (! array_filter($reci, fn (string $rec) => $this->istaRec($nazivRec, $rec))) {
                return false;
            }
        }

        return true;
    }

    // Padež menja kraj reči („molba" → „molbu", „zahtev" → „zahteva"), ali „molim" nije „molba": zajednički početak mora da bude
    // cela reč naziva bez poslednjeg slova. Reči do tri slova („CV") moraju da budu iste.
    private function istaRec(string $nazivRec, string $rec): bool
    {
        $duzina = strlen($nazivRec);

        if ($duzina <= 3) {
            return $nazivRec === $rec;
        }

        return strlen($rec) <= $duzina + 3 && str_starts_with($rec, substr($nazivRec, 0, $duzina - 1));
    }
}
