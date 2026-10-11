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
        $this->vrste = Prilika::vrsteSaJavnimPrilikama();
    }

    public function render(): View
    {
        return view('javno.vrata');
    }
}
