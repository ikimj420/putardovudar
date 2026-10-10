<?php

namespace App\Filament\Resources\Kalendar\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Kalendar\KalendarResource;
use Filament\Resources\Pages\EditRecord;

class EditKalendarStavka extends EditRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = KalendarResource::class;

    public function getTitle(): string
    {
        return 'Izmena stavke kalendara';
    }
}
