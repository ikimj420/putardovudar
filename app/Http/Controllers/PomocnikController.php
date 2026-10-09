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

        if ($validator->fails()) {
            return response()->view('javno.pomocnik', ['pitanje' => $pitanje, 'greska' => $validator->errors()->first('pitanje')], 422);
        }

        try {
            $odgovor = $pomocnik->odgovori($pitanje);
        } catch (OllamaNedostupna) {
            return view('javno.pomocnik', ['pitanje' => $pitanje, 'nijeDostupan' => true]);
        }

        return view('javno.pomocnik', ['pitanje' => $pitanje, 'odgovor' => $odgovor]);
    }
}
