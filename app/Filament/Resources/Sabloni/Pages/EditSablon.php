<?php

namespace App\Filament\Resources\Sabloni\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Sabloni\SabloniResource;
use Filament\Resources\Pages\EditRecord;

class EditSablon extends EditRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = SabloniResource::class;

    public function getTitle(): string
    {
        return 'Izmena šablona';
    }
}
