<?php

namespace App\Http\Controllers;

use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\Prikaz;
use App\Support\PrikazPocetne;
use Illuminate\Contracts\View\View;

class JavnaPocetnaController extends Controller
{
    // Početna (paket 57): naslovni deo, pločice i najbliže prilike; sve što se vidi ide kroz Prilika::javne() i ostale javne opsege.
    public function index(): View
    {
        return view('javno.pocetna', [
            'vrste' => Prilika::vrsteSaJavnimPrilikama(),
            'prilike' => Prilika::javne()->poRoku()->limit(6)->get(),
            'plocice' => $plocice = $this->plocice(),
            // Traka „Vodiči" postoji samo kad ima šta da se otvori.
            'imaVodica' => $plocice[1]['broj'] > 0,
        ]);
    }

    // Četiri pločice, svaka u boji svoje strane i sa vezom na nju; brojevi su samo ono što je javno (nacrt i prošao rok se ne broje).
    /** @return list<array{strana: string, adresa: string, broj: int, tekst: string}> */
    private function plocice(): array
    {
        $prilike = Prilika::javne()->count();
        $vodici = Vodic::javni()->count();
        $organizacije = Organizacija::javne()->count();
        $meseci = KalendarStavka::brojMesecaSaStavkama();

        return [
            ['strana' => 'prilike', 'adresa' => route('prilike.index'), 'broj' => $prilike, 'tekst' => Prikaz::oblik($prilike, PrikazPocetne::PLOCICA_PRILIKE)],
            ['strana' => 'vodici', 'adresa' => route('vodici.index'), 'broj' => $vodici, 'tekst' => Prikaz::oblik($vodici, PrikazPocetne::PLOCICA_VODICI)],
            ['strana' => 'organizacije', 'adresa' => route('organizacije.index'), 'broj' => $organizacije, 'tekst' => Prikaz::oblik($organizacije, PrikazPocetne::PLOCICA_ORGANIZACIJE)],
            ['strana' => 'kalendar', 'adresa' => route('kalendar.index'), 'broj' => $meseci, 'tekst' => Prikaz::oblik($meseci, PrikazPocetne::PLOCICA_KALENDAR)],
        ];
    }
}
