<?php

namespace App\Filament\Resources\Prilike\Pages;

use App\Filament\Resources\Prilike\PrilikaResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrilika extends CreateRecord
{
    protected static string $resource = PrilikaResource::class;

    // Filament ubacuje naziv u rečenicu („Napravi prilika"), što na srpskom nije padež.
    public function getTitle(): string
    {
        return 'Nova prilika';
    }
}
