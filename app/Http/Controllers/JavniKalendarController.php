<?php

namespace App\Http\Controllers;

use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\Prikaz;
use Illuminate\Contracts\View\View;

class JavniKalendarController extends Controller
{
    // Dvanaest meseci od tekućeg, a stavka stoji u mesecu svog roka (pre koliko godina nije bitno): „Prošli put: do 26.01.2026."
    // u januaru. Meseci bez stavki se ne crtaju. Samo kroz KalendarStavka::javne(): ništa se ne predviđa.
    public function index(): View
    {
        $stavke = KalendarStavka::javne()->orderBy('otvoreno_do', 'desc')->orderBy('id')->get();

        // Ista stvar je sada otvorena kad je javna prilika koju je Ivan izabrao uz stavku, ili kad javna prilika ima isti link izvora (KalendarStavka::sadaOtvorena).
        // Od prilike trebaju samo id i link (poređenje) i slug i naslov (veza i natpis), pa se ostale kolone ne učitavaju (paket 97).
        $otvorene = Prilika::javne()->orderBy('id')->get(['id', 'slug', 'naslov', 'link_izvora']);

        $meseci = [];

        for ($i = 0; $i < 12; $i++) {
            $mesec = today()->startOfMonth()->addMonths($i);
            $uMesecu = $stavke->filter(fn (KalendarStavka $stavka) => $stavka->otvoreno_do->month === $mesec->month)
                ->sortBy(fn (KalendarStavka $stavka) => $stavka->otvoreno_do->day)
                ->values();

            if ($uMesecu->isNotEmpty()) {
                $meseci[] = [
                    'naziv' => Prikaz::mesec($mesec->month),
                    'stavke' => $uMesecu->map(fn (KalendarStavka $stavka) => [
                        'stavka' => $stavka,
                        'prilika' => $stavka->sadaOtvorena($otvorene),
                    ])->all(),
                ];
            }
        }

        return view('javno.kalendar', ['meseci' => $meseci]);
    }
}
