<?php

namespace App\Http\Controllers;

use App\Models\Prilika;
use Illuminate\Contracts\View\View;

class JavnePrilikeController extends Controller
{
    public function index(): View
    {
        // Najbliži rok prvo; bez roka i stalno otvorene na kraju, jer im rok ne važi.
        $prilike = Prilika::javne()
            ->orderByRaw('rok IS NULL OR rok_stalno_otvoren')
            ->orderBy('rok')
            ->orderBy('id')
            ->get();

        return view('javno.spisak', ['prilike' => $prilike]);
    }

    // Nejavna prilika je za posetioca isto što i nepostojeća: 404, jer bi 403 potvrdio da zapis postoji.
    public function show(string $slug): View
    {
        $prilika = Prilika::javne()->where('slug', $slug)->firstOrFail();

        return view('javno.prilika', ['prilika' => $prilika]);
    }
}
