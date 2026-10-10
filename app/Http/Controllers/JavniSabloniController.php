<?php

namespace App\Http\Controllers;

use App\Models\Sablon;
use App\Support\Prikaz;
use App\Support\SklapanjeSablona;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class JavniSabloniController extends Controller
{
    public function index(): View
    {
        return view('javno.sabloni', ['sabloni' => Sablon::javni()->orderBy('naslov')->get()]);
    }

    // Nejavan šablon je za posetioca isto što i nepostojeći: 404, jer bi 403 potvrdio da zapis postoji.
    public function show(string $slug): View
    {
        $sablon = Sablon::javni()->where('slug', $slug)->firstOrFail();

        // Datum je unapred upisan današnji, da čovek ne kuca ono što strana već zna; polje se može menjati.
        return view('javno.sablon', ['sablon' => $sablon, 'unosi' => array_filter(['datum' => in_array('datum', $sablon->kljucevi(), true) ? Prikaz::datum(today()) : null]), 'tekst' => null]);
    }

    // Tekst se sklapa u memoriji i vraća u istom odgovoru: ništa se ne upisuje, ni u bazu ni u sesiju.
    public function sklopi(Request $zahtev, string $slug): View
    {
        $sablon = Sablon::javni()->where('slug', $slug)->firstOrFail();
        $unosi = [];

        foreach ($sablon->kljucevi() as $kljuc) {
            $unos = $zahtev->input($kljuc);
            $unosi[$kljuc] = is_string($unos) ? $unos : '';
        }

        return view('javno.sablon', ['sablon' => $sablon, 'unosi' => $unosi, 'tekst' => SklapanjeSablona::sklopi($sablon, $unosi)]);
    }
}
