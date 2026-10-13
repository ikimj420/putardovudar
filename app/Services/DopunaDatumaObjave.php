<?php

namespace App\Services;

use App\Enums\StatusPrilike;
use App\Models\Prilika;

// Nacrti uvezeni pre kolone izvor_objavljen nemaju datum objave, pa se rok bez godine („do 12. oktobra") ne prima. Kad izvor opet ponudi istu stavku, prazno polje
// se popunjava (paket 70). Ništa drugo se ne menja, ni vreme izmene: od njega zavisi da li se nacrt vidi kao neizmenjen pri uvoz:obradi --ponovo.
final class DopunaDatumaObjave
{
    /** Vraća true samo kad je datum upisan. */
    public function dopuni(Prilika $postojeca, ?string $datum): bool
    {
        if ($datum === null || $postojeca->status !== StatusPrilike::Nacrt || $postojeca->izvor_objavljen !== null || ! $postojeca->nijeMenjanRucno()) {
            return false;
        }

        // Upit bez modela, jer model bi pomerio updated_at.
        return Prilika::query()->whereKey($postojeca->getKey())->whereNull('izvor_objavljen')->toBase()->update(['izvor_objavljen' => $datum]) > 0;
    }
}
