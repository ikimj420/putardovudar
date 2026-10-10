<?php

namespace App\Filament\Resources\Vodici\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Vodici\VodicResource;
use Filament\Resources\Pages\EditRecord;

class EditVodic extends EditRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = VodicResource::class;

    public function getTitle(): string
    {
        return 'Izmena vodiča';
    }
}
