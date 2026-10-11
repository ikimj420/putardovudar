<?php

namespace App\View\Components;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

// Vrata u naslovnom delu svake javne strane (paket 62): jedno mesto za sliku i za oznake, a oznake su samo za vrste koje imaju javnu priliku.
final class Vrata extends Component
{
    /** @var list<VrstaPrilike> */
    public array $vrste;

    public function __construct()
    {
        // „Drugo" se ne piše u vratima (paket 67): vrata pokazuju najviše pet vrsta, da šesta oznaka ne siđe ispod poda vrata na telefonu. Izbor „Drugo" iznad
        // kartica na strani Prilike ostaje.
        $this->vrste = array_values(array_filter(Prilika::vrsteSaJavnimPrilikama(), fn (VrstaPrilike $vrsta) => $vrsta !== VrstaPrilike::Drugo));
    }

    public function render(): View
    {
        return view('javno.vrata');
    }
}
