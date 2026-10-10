<?php

namespace App\Http\Controllers;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class JavnePrilikeController extends Controller
{
    public function index(Request $zahtev): View
    {
        // Dugme postoji samo za vrstu koja ima bar jednu javnu priliku, u redosledu vrsta.
        $postojece = Prilika::javne()->distinct()->pluck('vrsta');
        $vrste = array_values(array_filter(VrstaPrilike::cases(), fn (VrstaPrilike $vrsta) => $postojece->contains($vrsta)));

        // Nepoznata vrsta u adresi (i vrsta bez javne prilike) daje sve prilike, ne praznu stranu ni grešku.
        $trazena = $zahtev->query('vrsta');
        $izabrana = is_string($trazena) ? VrstaPrilike::tryFrom($trazena) : null;
        $izabrana = $izabrana !== null && in_array($izabrana, $vrste, true) ? $izabrana : null;

        // Najbliži rok prvo; bez roka i stalno otvorene na kraju, jer im rok ne važi.
        $prilike = Prilika::javne()
            ->when($izabrana, fn ($upit, VrstaPrilike $vrsta) => $upit->where('vrsta', $vrsta))
            ->orderByRaw('rok IS NULL OR rok_stalno_otvoren')
            ->orderBy('rok')
            ->orderBy('id')
            ->get();

        return view('javno.spisak', ['prilike' => $prilike, 'vrste' => $vrste, 'izabrana' => $izabrana]);
    }

    // Nejavna prilika je za posetioca isto što i nepostojeća: 404, jer bi 403 potvrdio da zapis postoji.
    public function show(string $slug): View
    {
        $prilika = Prilika::javne()->where('slug', $slug)->firstOrFail();

        return view('javno.prilika', ['prilika' => $prilika]);
    }
}
