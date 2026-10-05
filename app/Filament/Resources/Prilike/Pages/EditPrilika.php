<?php

namespace App\Filament\Resources\Prilike\Pages;

use App\Filament\Resources\Prilike\PrilikaResource;
use Filament\Resources\Pages\EditRecord;

class EditPrilika extends EditRecord
{
    protected static string $resource = PrilikaResource::class;

    // Filament ubacuje naziv u rečenicu („Napravi prilika"), što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Izmena prilike';
    }
}
