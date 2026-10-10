<?php

namespace App\Filament\Resources\Organizacije;

use App\Filament\Resources\Organizacije\Pages\CreateOrganizacija;
use App\Filament\Resources\Organizacije\Pages\EditOrganizacija;
use App\Filament\Resources\Organizacije\Pages\ListOrganizacije;
use App\Filament\Resources\Organizacije\Schemas\OrganizacijaForm;
use App\Filament\Resources\Organizacije\Tables\OrganizacijeTable;
use App\Models\Organizacija;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrganizacijaResource extends Resource
{
    protected static ?string $model = Organizacija::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'organizacija';

    protected static ?string $pluralModelLabel = 'organizacije';

    protected static ?string $navigationLabel = 'Organizacije';

    protected static ?string $recordTitleAttribute = 'naziv';

    // Bez ovoga Filament množinu pravi po engleskom pravilu.
    protected static ?string $slug = 'organizacije';

    public static function form(Schema $schema): Schema
    {
        return OrganizacijaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrganizacijeTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizacije::route('/'),
            'create' => CreateOrganizacija::route('/create'),
            'edit' => EditOrganizacija::route('/{record}/edit'),
        ];
    }
}
