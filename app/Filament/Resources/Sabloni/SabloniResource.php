<?php

namespace App\Filament\Resources\Sabloni;

use App\Filament\Resources\Sabloni\Pages\CreateSablon;
use App\Filament\Resources\Sabloni\Pages\EditSablon;
use App\Filament\Resources\Sabloni\Pages\ListSabloni;
use App\Filament\Resources\Sabloni\Schemas\SablonForm;
use App\Filament\Resources\Sabloni\Tables\SabloniTable;
use App\Models\Sablon;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SabloniResource extends Resource
{
    protected static ?string $model = Sablon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'šablon';

    protected static ?string $pluralModelLabel = 'šabloni';

    protected static ?string $navigationLabel = 'Šabloni';

    protected static ?string $recordTitleAttribute = 'naslov';

    // Bez ovoga Filament množinu pravi po engleskom pravilu.
    protected static ?string $slug = 'sabloni';

    public static function form(Schema $schema): Schema
    {
        return SablonForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SabloniTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSabloni::route('/'),
            'create' => CreateSablon::route('/create'),
            'edit' => EditSablon::route('/{record}/edit'),
        ];
    }
}
