<?php

namespace App\Filament\Resources\Predlozi;

use App\Filament\Resources\Predlozi\Pages\ListPredlozi;
use App\Filament\Resources\Predlozi\Tables\PredloziTable;
use App\Models\Predlog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Predlozi posetilaca (paket 79): spisak za pregled; predlog se ne pravi ni ne menja ovde, samo se označava pregledanim ili briše.
class PredlogResource extends Resource
{
    protected static ?string $model = Predlog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $modelLabel = 'predlog';

    protected static ?string $pluralModelLabel = 'predlozi';

    protected static ?string $navigationLabel = 'Predlozi';

    protected static ?string $recordTitleAttribute = 'link';

    // Bez ovoga Filament množinu pravi po engleskom pravilu.
    protected static ?string $slug = 'predlozi';

    public static function table(Table $table): Table
    {
        return PredloziTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPredlozi::route('/'),
        ];
    }
}
