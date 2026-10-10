<?php

namespace App\Services\Pomocnik;

use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use Illuminate\Support\Collection;

final readonly class RezultatPretrage
{
    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     * @param  Collection<int, Organizacija>  $organizacije
     */
    public function __construct(public Formular $formular, public Collection $zapisi, public Collection $vodici, public Collection $organizacije = new Collection) {}

    // „Nemam podatak" samo kad nema ni prilika, ni vodiča, ni organizacija.
    public function nemaPodatak(): bool
    {
        return $this->zapisi->isEmpty() && $this->vodici->isEmpty() && $this->organizacije->isEmpty();
    }
}
