<?php

namespace App\Services;

use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\PrikazKalendara;
use InvalidArgumentException;

// „Dodaj u kalendar" na kartici prilike kojoj je prošao rok: nacrt stavke popunjen iz prilike (naziv, izvor, link, rok i grupe).
// Isti rok iste prilike se ne dodaje dvaput: drugi klik vraća stavku koja već postoji.
final class KalendarIzPrilike
{
    public function dodaj(Prilika $prilika): KalendarStavka
    {
        if (! $prilika->rokJeProsao() || $prilika->rok === null) {
            throw new InvalidArgumentException('U kalendar ide samo prilika kojoj je rok prošao.');
        }

        $postojeca = KalendarStavka::query()->whereDate('otvoreno_do', $prilika->rok)->get()
            ->first(fn (KalendarStavka $stavka) => $this->istaStvar($stavka, $prilika));

        if ($postojeca !== null) {
            return $postojeca;
        }

        return KalendarStavka::query()->create([
            'naziv' => $prilika->naslov,
            'ko_daje' => $prilika->naziv_izvora,
            'grupe' => $prilika->grupe,
            'otvoreno_do' => $prilika->rok,
            'link_izvora' => $prilika->link_izvora,
        ]);
    }

    // Isti link izvora je ista stvar; bez linka se poredi naziv.
    private function istaStvar(KalendarStavka $stavka, Prilika $prilika): bool
    {
        return blank($prilika->link_izvora)
            ? blank($stavka->link_izvora) && $stavka->naziv === $prilika->naslov
            : PrikazKalendara::istiLink($stavka->link_izvora, $prilika->link_izvora);
    }
}
