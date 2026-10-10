<?php

namespace App\Filament\Resources\Vodici\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Vodici\VodicResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVodic extends CreateRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = VodicResource::class;

    // Filament ubacuje naziv u rečenicu, što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Novi vodič';
    }
}
