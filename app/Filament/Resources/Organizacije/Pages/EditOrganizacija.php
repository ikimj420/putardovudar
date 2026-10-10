<?php

namespace App\Filament\Resources\Organizacije\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Organizacije\OrganizacijaResource;
use Filament\Resources\Pages\EditRecord;

class EditOrganizacija extends EditRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = OrganizacijaResource::class;

    public function getTitle(): string
    {
        return 'Izmena organizacije';
    }
}
