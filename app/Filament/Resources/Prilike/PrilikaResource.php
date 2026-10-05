<?php

namespace App\Filament\Resources\Prilike;

use App\Filament\Resources\Prilike\Pages\CreatePrilika;
use App\Filament\Resources\Prilike\Pages\EditPrilika;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Filament\Resources\Prilike\Schemas\PrilikaForm;
use App\Filament\Resources\Prilike\Tables\PrilikeTable;
use App\Models\Prilika;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PrilikaResource extends Resource
{
    protected static ?string $model = Prilika::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'prilika';

    protected static ?string $pluralModelLabel = 'prilike';

    protected static ?string $navigationLabel = 'Prilike';

    protected static ?string $recordTitleAttribute = 'naslov';

    // Bez ovoga Filament množinu pravi po engleskom pravilu („prilikas“).
    protected static ?string $slug = 'prilike';

    public static function form(Schema $schema): Schema
    {
        return PrilikaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrilikeTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrilike::route('/'),
            'create' => CreatePrilika::route('/create'),
            'edit' => EditPrilika::route('/{record}/edit'),
        ];
    }
}
