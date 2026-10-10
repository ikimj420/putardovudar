<?php

namespace App\Http\Controllers;

use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\Prikaz;
use App\Support\PrikazKalendara;
use Illuminate\Contracts\View\View;

class JavniKalendarController extends Controller
{
    // Dvanaest meseci od tekućeg, a stavka stoji u mesecu svog roka (pre koliko godina nije bitno): „Prošli put: do 26.01.2026."
    // u januaru. Meseci bez stavki se ne crtaju. Samo kroz KalendarStavka::javne(): ništa se ne predviđa.
    public function index(): View
    {
        $stavke = KalendarStavka::javne()->orderBy('otvoreno_do', 'desc')->orderBy('id')->get();

        // Ista stvar je sada otvorena kad javna prilika ima isti link izvora.
        $otvorene = Prilika::javne()->whereNotNull('link_izvora')->orderBy('id')->get();

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
                        'prilika' => $otvorene->first(fn (Prilika $prilika) => PrikazKalendara::istiLink($prilika->link_izvora, $stavka->link_izvora)),
                    ])->all(),
                ];
            }
        }

        return view('javno.kalendar', ['meseci' => $meseci]);
    }
}
