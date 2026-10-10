<?php

namespace App\Filament\Resources\Organizacije\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Organizacije\OrganizacijaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganizacija extends CreateRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = OrganizacijaResource::class;

    // Filament ubacuje naziv u rečenicu, što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Nova organizacija';
    }
}
