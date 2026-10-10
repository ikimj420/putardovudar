<?php

namespace App\Services\Pomocnik;

use App\Models\Vodic;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Vodiči se traže samo po prihvaćenim ključnim rečima u naslovu i samo kroz Vodic::javni(). Reč „vodič" ne sužava pretragu,
// a kad su sve reči „vodič" ili „organizacija", izlistavaju se objavljeni vodiči po nazivu.
final class PretragaVodica
{
    public const NAJVISE_ZAPISA = 3;

    public const NAJVISE_U_SPISKU = 5;

    private const NAJVISE_KANDIDATA = 300;

    /** @return Collection<int, Vodic> */
    public function pronadji(Formular $formular): Collection
    {
        $reci = new OpsteReci($formular->reciZa(OpsteReci::VODIC));

        if ($reci->jeSamoOpste()) {
            return $reci->sadrzi(OpsteReci::VODIC) ? Vodic::javni()->orderBy('naslov')->limit(self::NAJVISE_U_SPISKU)->get() : new Collection;
        }

        $izrazi = $reci->izrazi(OpsteReci::VODIC);

        if ($izrazi === []) {
            return new Collection;
        }

        return Vodic::javni()->orderBy('naslov')->limit(self::NAJVISE_KANDIDATA)->get()
            ->filter(fn (Vodic $vodic) => $this->sadrziSveIzraze($vodic, $izrazi))
            ->take(self::NAJVISE_ZAPISA)
            ->values();
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
