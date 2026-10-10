<?php

namespace App\Filament\Resources\Kalendar;

use App\Filament\Resources\Kalendar\Pages\CreateKalendarStavka;
use App\Filament\Resources\Kalendar\Pages\EditKalendarStavka;
use App\Filament\Resources\Kalendar\Pages\ListKalendar;
use App\Filament\Resources\Kalendar\Schemas\KalendarForm;
use App\Filament\Resources\Kalendar\Tables\KalendarTable;
use App\Models\KalendarStavka;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KalendarResource extends Resource
{
    protected static ?string $model = KalendarStavka::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'stavka kalendara';

    protected static ?string $pluralModelLabel = 'kalendar';

    protected static ?string $navigationLabel = 'Kalendar';

    protected static ?string $recordTitleAttribute = 'naziv';

    // Bez ovoga Filament množinu pravi po engleskom pravilu.
    protected static ?string $slug = 'kalendar';

    public static function form(Schema $schema): Schema
    {
        return KalendarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KalendarTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKalendar::route('/'),
            'create' => CreateKalendarStavka::route('/create'),
            'edit' => EditKalendarStavka::route('/{record}/edit'),
        ];
    }
}
