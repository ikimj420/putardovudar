<?php

namespace App\Filament\Resources\Organizacije\Pages;

use App\Filament\Resources\Organizacije\OrganizacijaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrganizacije extends ListRecords
{
    protected static string $resource = OrganizacijaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova organizacija'),
        ];
    }
}
