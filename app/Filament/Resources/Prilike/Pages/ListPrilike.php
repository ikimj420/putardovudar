<?php

namespace App\Filament\Resources\Prilike\Pages;

use App\Filament\Resources\Prilike\PrilikaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrilike extends ListRecords
{
    protected static string $resource = PrilikaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova prilika'),
        ];
    }
}
