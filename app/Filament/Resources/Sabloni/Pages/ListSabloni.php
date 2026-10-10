<?php

namespace App\Filament\Resources\Sabloni\Pages;

use App\Filament\Resources\Sabloni\SabloniResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSabloni extends ListRecords
{
    protected static string $resource = SabloniResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novi šablon'),
        ];
    }
}
