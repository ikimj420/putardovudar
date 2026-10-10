<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Models\Vodic;
use Illuminate\Support\Collection;

final readonly class RezultatPretrage
{
    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     */
    public function __construct(public Formular $formular, public Collection $zapisi, public Collection $vodici) {}

    // „Nemam podatak" samo kad nema ni prilika ni vodiča.
    public function nemaPodatak(): bool
    {
        return $this->zapisi->isEmpty() && $this->vodici->isEmpty();
    }
}
