<?php

namespace App\Http\Controllers;

use App\Services\Ollama\OllamaNedostupna;
use App\Services\Pomocnik\Pomocnik;
use App\Support\PrikazPomocnika;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PomocnikController extends Controller
{
    public function index(): View
    {
        return view('javno.pomocnik');
    }

    // Pitanje se šalje POST-om, da ne završi u adresi; pomoćnik koji ne radi gasi samo sebe, nikad stranu.
    public function pitaj(Request $request, Pomocnik $pomocnik): View|Response
    {
        $validator = Validator::make($request->all(), ['pitanje' => ['required', 'string', 'max:'.Pomocnik::NAJVISE_ZNAKOVA_PITANJA]], [
            'pitanje.required' => PrikazPomocnika::GRESKA_PRAZNO,
            'pitanje.max' => PrikazPomocnika::GRESKA_DUGACKO,
            'pitanje.string' => PrikazPomocnika::GRESKA_PRAZNO,
        ]);
        $pitanje = is_string($request->input('pitanje')) ? $request->input('pitanje') : '';

        // Isto pitanje, ista pravila i ista provera: plutajući prozor samo traži odgovor bez okvira strane (X-Requested-With).
        $pogled = $request->ajax() ? 'javno.pomocnik-fragment' : 'javno.pomocnik';

        if ($validator->fails()) {
            return response()->view($pogled, ['pitanje' => $pitanje, 'greska' => $validator->errors()->first('pitanje')], 422);
        }

        try {
            $odgovor = $pomocnik->odgovori($pitanje);
        } catch (OllamaNedostupna) {
            return view($pogled, ['pitanje' => $pitanje, 'nijeDostupan' => true]);
        }

        return view($pogled, ['pitanje' => $pitanje, 'odgovor' => $odgovor]);
    }
}
