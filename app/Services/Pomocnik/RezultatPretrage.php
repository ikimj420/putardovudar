<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use Illuminate\Support\Collection;

final readonly class RezultatPretrage
{
    /** @param  Collection<int, Prilika>  $zapisi */
    public function __construct(public Formular $formular, public Collection $zapisi) {}

    public function nemaPodatak(): bool
    {
        return $this->zapisi->isEmpty();
    }
}
