<?php

namespace App\Filament\Resources\Sabloni\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Sabloni\SabloniResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSablon extends CreateRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = SabloniResource::class;

    // Filament ubacuje naziv u rečenicu, što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Novi šablon';
    }
}
