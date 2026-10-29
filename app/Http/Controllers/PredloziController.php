<?php

namespace App\Http\Controllers;

use App\Models\Predlog;
use App\Support\LinkSajta;
use App\Support\PrikazPredloga;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PredloziController extends Controller
{
    public function forma(): View
    {
        return view('javno.predlozi');
    }

    // Čuva se samo link i napomena (nikad ime, mejl, telefon ni IP); predlog nije prilika i ne ide u obradu. Greška vraća formu sa unetim i statusom 422, kao pomoćnik.
    public function posalji(Request $request): RedirectResponse|Response
    {
        $validator = Validator::make($request->all(), [
            'link' => ['bail', 'required', 'string', 'max:'.Predlog::NAJVISE_ZNAKOVA_LINKA, function (string $atribut, mixed $vrednost, Closure $neuspeh): void {
                if (! LinkSajta::jeIspravan((string) $vrednost)) {
                    $neuspeh(PrikazPredloga::GRESKA_LINK);
                }
            }],
            'napomena' => ['nullable', 'string', 'max:'.Predlog::NAJVISE_ZNAKOVA_NAPOMENE],
        ], [
            'link.required' => PrikazPredloga::GRESKA_PRAZNO,
            'link.string' => PrikazPredloga::GRESKA_LINK,
            'link.max' => PrikazPredloga::GRESKA_LINK_DUGACAK,
            'napomena.string' => PrikazPredloga::GRESKA_NAPOMENA_DUGACKA,
            'napomena.max' => PrikazPredloga::GRESKA_NAPOMENA_DUGACKA,
        ]);

        if ($validator->fails()) {
            return response()->view('javno.predlozi', [
                'link' => is_string($request->input('link')) ? $request->input('link') : '',
                'napomena' => is_string($request->input('napomena')) ? $request->input('napomena') : '',
                'greske' => $validator->errors()->toArray(),
            ], 422);
        }

        Predlog::query()->create(['link' => $validator->validated()['link'], 'napomena' => $validator->validated()['napomena'] ?? null]);

        return redirect()->route('predlozi.forma')->with('poslato', true);
    }
}
