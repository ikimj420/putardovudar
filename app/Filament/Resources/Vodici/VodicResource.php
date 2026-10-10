<?php

namespace App\Filament\Resources\Vodici;

use App\Filament\Resources\Vodici\Pages\CreateVodic;
use App\Filament\Resources\Vodici\Pages\EditVodic;
use App\Filament\Resources\Vodici\Pages\ListVodici;
use App\Filament\Resources\Vodici\Schemas\VodicForm;
use App\Filament\Resources\Vodici\Tables\VodiciTable;
use App\Models\Vodic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VodicResource extends Resource
{
    protected static ?string $model = Vodic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'vodič';

    protected static ?string $pluralModelLabel = 'vodiči';

    protected static ?string $navigationLabel = 'Vodiči';

    protected static ?string $recordTitleAttribute = 'naslov';

    // Bez ovoga Filament množinu pravi po engleskom pravilu.
    protected static ?string $slug = 'vodici';

    public static function form(Schema $schema): Schema
    {
        return VodicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VodiciTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVodici::route('/'),
            'create' => CreateVodic::route('/create'),
            'edit' => EditVodic::route('/{record}/edit'),
        ];
    }
}
