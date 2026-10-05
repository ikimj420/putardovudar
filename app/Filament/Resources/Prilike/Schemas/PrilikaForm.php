<?php

namespace App\Filament\Resources\Prilike\Schemas;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\Prikaz;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PrilikaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('naslov')
                    ->label('Naslov')
                    ->helperText('Kratak naslov koji se vidi na spisku, npr. „Radnik u skladištu, Niš“.')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Deo adrese strane; ako ostane prazno, pravi se iz naslova.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Select::make('vrsta')
                    ->label('Vrsta')
                    ->helperText('Izaberi šta je ova prilika.')
                    ->options(VrstaPrilike::class)
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->helperText('Nacrt se ne vidi javno, Objavljeno se vidi do isteka roka, Arhivirano se ne vidi.')
                    ->options(StatusPrilike::class)
                    ->default(StatusPrilike::Nacrt)
                    ->live()
                    ->required(),
                Textarea::make('kratak_opis')
                    ->label('Kratak opis')
                    ->helperText('Jedna do dve rečenice za spisak, npr. „Puno radno vreme, plata po dogovoru.“')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('opis')
                    ->label('Opis')
                    ->helperText('Sve što čovek treba da zna pre prijave: uslovi, dokumenta, kako se prijavljuje.')
                    ->columnSpanFull(),
                DatePicker::make('rok')
                    ->label('Rok')
                    ->helperText('Poslednji dan za prijavu; ostavi prazno ako roka nema.')
                    ->native(false)
                    ->displayFormat(Prikaz::DATUM),
                Toggle::make('rok_stalno_otvoren')
                    ->label('Rok stalno otvoren')
                    ->helperText('Uključi ako se može prijaviti uvek; tada se rok ne gleda.'),
                TextInput::make('mesto')
                    ->label('Mesto')
                    ->helperText('Grad ili opština, npr. „Niš“; ostavi prazno ako nije vezano za mesto.')
                    ->maxLength(255),
                Toggle::make('online')
                    ->label('Online')
                    ->helperText('Uključi ako se sve radi preko interneta.'),
                TextInput::make('naziv_izvora')
                    ->label('Naziv izvora')
                    ->helperText('Ko je objavio priliku, npr. „Nacionalna služba za zapošljavanje“.')
                    ->maxLength(255),
                TextInput::make('link_izvora')
                    ->label('Link izvora')
                    ->helperText('Adresa strane odakle je preuzeto; bez nje se prilika ne može objaviti.')
                    ->url()
                    ->required(fn (Get $get): bool => self::statusZahtevaIzvor($get('status')))
                    ->validationMessages(['required' => Prilika::PORUKA_BEZ_IZVORA])
                    ->maxLength(2048),
            ]);
    }

    private static function statusZahtevaIzvor(mixed $status): bool
    {
        $status = $status instanceof StatusPrilike ? $status : StatusPrilike::tryFrom((string) $status);

        return $status?->zahtevaIzvor() ?? false;
    }
}
