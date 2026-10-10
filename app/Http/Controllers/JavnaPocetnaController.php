<?php

namespace App\Http\Controllers;

use App\Models\Prilika;
use Illuminate\Contracts\View\View;

class JavnaPocetnaController extends Controller
{
    // Početna (paket 57): naslovni deo, pločice i najbliže prilike; sve što se vidi ide kroz Prilika::javne() i ostale javne opsege.
    public function index(): View
    {
        return view('javno.pocetna', [
            'vrste' => Prilika::vrsteSaJavnimPrilikama(),
            'prilike' => Prilika::javne()->poRoku()->limit(6)->get(),
        ]);
    }
}
