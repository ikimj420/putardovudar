<?php

namespace App\Filament\Resources\Prilike\Pages;

use App\Filament\Concerns\ProveraSamoNaServeru;
use App\Filament\Resources\Prilike\PrilikaResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditPrilika extends EditRecord
{
    use ProveraSamoNaServeru;

    protected static string $resource = PrilikaResource::class;

    // Predlog Ollame je samo za čitanje, ali bi ga forma pri snimanju prepisala praznim nizom.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::except($data, ['predlog']);
    }

    // Filament ubacuje naziv u rečenicu („Napravi prilika"), što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Izmena prilike';
    }
}
