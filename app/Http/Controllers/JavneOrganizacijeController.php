<?php

namespace App\Http\Controllers;

use App\Models\Organizacija;
use Illuminate\Contracts\View\View;

class JavneOrganizacijeController extends Controller
{
    public function index(): View
    {
        return view('javno.organizacije', ['organizacije' => Organizacija::javne()->orderBy('naziv')->get()]);
    }

    // Nejavna organizacija je za posetioca isto što i nepostojeća: 404, jer bi 403 potvrdio da zapis postoji.
    public function show(string $slug): View
    {
        return view('javno.organizacija', ['organizacija' => Organizacija::javne()->where('slug', $slug)->firstOrFail()]);
    }
}
