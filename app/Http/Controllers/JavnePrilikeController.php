<?php

namespace App\Http\Controllers;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPrilike;
use App\Support\RokUKalendaru;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class JavnePrilikeController extends Controller
{
    public function index(Request $zahtev): View
    {
        // Dugme postoji samo za vrstu koja ima bar jednu javnu priliku, u redosledu vrsta.
        $vrste = Prilika::vrsteSaJavnimPrilikama();

        // Nepoznata vrsta u adresi (i vrsta bez javne prilike) daje sve prilike, ne praznu stranu ni grešku.
        $trazena = $zahtev->query('vrsta');
        $izabrana = is_string($trazena) ? VrstaPrilike::tryFrom($trazena) : null;
        $izabrana = $izabrana !== null && in_array($izabrana, $vrste, true) ? $izabrana : null;

        // Grupe se na sajtu ne biraju (paket 54): ?grupa= iz starih veza se ne čita, pa daje isto što i adresa bez njega.

        // Pretraga (paket 57): jedno polje, po naslovu i kratkom opisu, zajedno sa izabranom vrstom; predugačak upit se skraćuje.
        $upisano = $zahtev->query('q');
        $pretraga = is_string($upisano) ? trim(mb_substr($upisano, 0, Prilika::NAJVISE_ZNAKOVA_U_PRETRAZI)) : '';

        $prilike = Prilika::javne()
            ->when($izabrana, fn ($upit, VrstaPrilike $vrsta) => $upit->where('vrsta', $vrsta))
            ->pretraga($pretraga)
            ->poRoku()
            ->get();

        return view('javno.spisak', ['prilike' => $prilike, 'vrste' => $vrste, 'izabrana' => $izabrana, 'pretraga' => $pretraga]);
    }

    // Nejavna prilika je za posetioca isto što i nepostojeća: 404, jer bi 403 potvrdio da zapis postoji.
    public function show(string $slug): View
    {
        $prilika = Prilika::javne()->where('slug', $slug)->firstOrFail();

        return view('javno.prilika', ['prilika' => $prilika]);
    }

    // Isto pravilo kao strana: samo javna prilika, i samo sa rokom koji važi; sve ostalo je 404.
    public function rok(string $slug): Response
    {
        $prilika = Prilika::javne()->where('slug', $slug)->firstOrFail();

        abort_unless(PrikazPrilike::imaRokZaTelefon($prilika), 404);

        return response(RokUKalendaru::sadrzaj($prilika, route('prilike.show', $prilika->slug), now()), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="rok-'.$prilika->slug.'.ics"',
        ]);
    }
}
