<?php

namespace App\Http\Controllers;

use App\Models\Vodic;
use Illuminate\Contracts\View\View;

class JavniVodiciController extends Controller
{
    public function index(): View
    {
        return view('javno.vodici', ['vodici' => Vodic::javni()->orderBy('naslov')->get()]);
    }

    // Nejavan vodič je za posetioca isto što i nepostojeći: 404, jer bi 403 potvrdio da zapis postoji.
    public function show(string $slug): View
    {
        return view('javno.vodic', ['vodic' => Vodic::javni()->where('slug', $slug)->firstOrFail()]);
    }
}
