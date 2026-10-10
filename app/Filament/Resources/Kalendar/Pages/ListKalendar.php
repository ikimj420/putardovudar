<?php

namespace App\Filament\Resources\Kalendar\Pages;

use App\Filament\Resources\Kalendar\KalendarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKalendar extends ListRecords
{
    protected static string $resource = KalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova stavka'),
        ];
    }
}
