<?php

namespace App\Filament\Resources\Kalendar\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Kalendar\KalendarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKalendarStavka extends CreateRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = KalendarResource::class;

    // Filament ubacuje naziv u rečenicu, što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Nova stavka kalendara';
    }
}
