<?php

namespace App\Services\Pomocnik;

use Illuminate\Support\Facades\Cache;

// Claude se plaća po upotrebi, pa ceo sajt ima dnevnu granicu pitanja i „Napiši moj" (Ivan, 05.11.2026., paket 100/T8). U kešu stoji samo broj za taj dan, ništa o tome ko je pitao.
final class DnevnaGranica
{
    /** Računa ovo pitanje i kaže da li staje u današnju granicu; poziva se tek kad bi se model stvarno zvao. */
    public function iskoristi(): bool
    {
        $kljuc = 'pomocnik:pitanja:'.now()->format('Y-m-d');

        Cache::add($kljuc, 0, now()->endOfDay());

        return (int) Cache::increment($kljuc) <= (int) config('pomocnik.pitanja_dnevno');
    }
}
