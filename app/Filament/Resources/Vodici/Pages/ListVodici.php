<?php

namespace App\Filament\Resources\Vodici\Pages;

use App\Filament\Resources\Vodici\VodicResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVodici extends ListRecords
{
    protected static string $resource = VodicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novi vodič'),
        ];
    }
}
