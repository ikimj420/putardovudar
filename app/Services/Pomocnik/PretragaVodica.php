<?php

namespace App\Services\Pomocnik;

use App\Models\Vodic;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Vodiči se traže samo po prihvaćenim ključnim rečima u naslovu i samo kroz Vodic::javni(). Reč „vodič" ne sužava pretragu.
final class PretragaVodica
{
    public const NAJVISE_ZAPISA = 3;

    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, Vodic> */
    public function pronadji(Formular $formular): Collection
    {
        $izrazi = $this->izrazi($formular->kljucneReci);

        if ($izrazi === []) {
            return new Collection;
        }

        return Vodic::javni()->orderBy('naslov')->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (Vodic $vodic) => $this->sadrziSveIzraze($vodic, $izrazi))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
    }

    // Reč „vodič" (u bilo kom padežu) ponavlja vrstu strane koja se traži, pa ne sužava; izraz od same te reči otpada.
    /**
     * @param  list<string>  $kljucneReci
     * @return list<string> normalizovani izrazi
     */
    private function izrazi(array $kljucneReci): array
    {
        $izrazi = [];

        foreach ($kljucneReci as $izraz) {
            $reci = array_filter(explode(' ', PoredjenjeTeksta::normalizuj($izraz)), fn (string $rec) => $rec !== '' && ! str_starts_with($rec, 'vodic'));

            if ($reci !== []) {
                $izrazi[] = implode(' ', $reci);
            }
        }

        return $izrazi;
    }

    /** @param  list<string>  $izrazi */
    private function sadrziSveIzraze(Vodic $vodic, array $izrazi): bool
    {
        $naslov = PoredjenjeTeksta::normalizuj($vodic->naslov);

        foreach ($izrazi as $izraz) {
            if (! PoredjenjeTeksta::sadrziIzraz($naslov, $izraz)) {
                return false;
            }
        }

        return true;
    }
}
