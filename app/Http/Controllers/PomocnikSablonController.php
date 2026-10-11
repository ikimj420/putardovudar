<?php

namespace App\Http\Controllers;

use App\Models\Sablon;
use App\Models\Vodic;
use App\Services\Pomocnik\PisanjeSablona;
use App\Support\Prikaz;
use App\Support\PrikazPomocnika;
use App\Support\SklapanjeSablona;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// „Napiši moj": pitanja šablona, pa tekst koji pomoćnik piše iz odgovora. Ništa što čovek upiše se ne čuva ni u bazi ni u sesiji.
class PomocnikSablonController extends Controller
{
    // Nejavan šablon je za posetioca isto što i nepostojeći: 404, jer bi 403 potvrdio da zapis postoji.
    public function forma(Request $zahtev, string $slug): View
    {
        $sablon = self::javniSablon($slug);

        // Datum je unapred upisan današnji, da čovek ne kuca ono što strana već zna; polje se može menjati. Obrazac crta samo polja šablona.
        return view(self::pogled($zahtev), ['sablon' => $sablon, 'unosi' => ['datum' => Prikaz::datum(today())]]);
    }

    public function napisi(Request $zahtev, string $slug, PisanjeSablona $pisanje): View
    {
        $sablon = self::javniSablon($slug);
        $unosi = self::unosi($sablon, $zahtev);

        // Primer oblika je prvi primer prvog objavljenog vodiča sa ovim šablonom koji ga ima; model ga koristi kao uzor, ne kao izvor podataka.
        $primer = Vodic::javni()->where('sablon_id', $sablon->getKey())->orderBy('id')->get()->map(fn (Vodic $vodic) => $vodic->prviPrimer())->first(fn (?string $primer) => $primer !== null);

        return view(self::pogled($zahtev), ['sablon' => $sablon, 'unosi' => $unosi, 'napisan' => $pisanje->napisi($sablon, $unosi, $primer)]);
    }

    // Preko granice posetilac dobija obrazac sa onim što je upisao i porukom (429), kao i pitanje pomoćniku.
    /** @param  array<string, list<string>>  $zaglavlja */
    public static function previseZahteva(Request $zahtev, array $zaglavlja): Response
    {
        $sablon = self::javniSablon((string) $zahtev->route('slug'));

        return response()->view(self::pogled($zahtev), ['sablon' => $sablon, 'unosi' => self::unosi($sablon, $zahtev), 'greska' => PrikazPomocnika::PREVISE_PITANJA], 429, $zaglavlja);
    }

    private static function javniSablon(string $slug): Sablon
    {
        return Sablon::javni()->where('slug', $slug)->firstOrFail();
    }

    // Plutajući prozor traži samo ono što stiže posle pitanja (X-Requested-With), strana pomoćnika celu stranu.
    private static function pogled(Request $zahtev): string
    {
        return $zahtev->ajax() ? 'javno.pomocnik-sablon-fragment' : 'javno.pomocnik-sablon';
    }

    /** @return array<string, string> ključ polja → šta je čovek upisao; ključ koji nije u šablonu se ignoriše */
    private static function unosi(Sablon $sablon, Request $zahtev): array
    {
        $polja = $zahtev->input('polje');
        $unosi = [];

        foreach ($sablon->kljucevi() as $kljuc) {
            $unos = is_array($polja) ? ($polja[$kljuc] ?? null) : null;
            $unosi[$kljuc] = is_string($unos) ? mb_substr($unos, 0, SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA) : '';
        }

        return $unosi;
    }
}
