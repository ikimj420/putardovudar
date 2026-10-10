<?php

namespace App\Filament\Resources\Organizacije\Schemas;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Organizacija;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OrganizacijaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('naziv')
                    ->label('Naziv')
                    ->helperText('Pun naziv organizacije, npr. „Nacionalna služba za zapošljavanje“.')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Deo adrese strane; ako ostane prazno, pravi se iz naziva.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Select::make('vrsta')
                    ->label('Vrsta')
                    ->helperText('Izaberi kakva je organizacija.')
                    ->options(VrstaOrganizacije::class)
                    ->required(),
                Textarea::make('kratak_opis')
                    ->label('Kratak opis')
                    ->helperText('Jedna do dve rečenice za spisak, npr. „Javna služba za oglase za posao i obuke.“')
                    ->required()
                    ->autosize()
                    ->columnSpanFull(),
                Textarea::make('opis')
                    ->label('Opis')
                    ->helperText('Čime se organizacija bavi i kako joj se čovek obraća; novi red u tekstu je novi red na strani.')
                    ->rows(6)
                    ->autosize()
                    ->columnSpanFull(),
                Textarea::make('mesto')
                    ->label('Mesto')
                    ->helperText('Gde se organizacija nalazi, npr. „Terazije 39, Beograd“.')
                    ->rows(1)
                    ->autosize()
                    ->columnSpanFull(),
                Toggle::make('online')
                    ->label('Online')
                    ->helperText('Uključi ako se usluge dobijaju i preko interneta.'),
                TextInput::make('telefon')
                    ->label('Telefon')
                    ->helperText('Broj za kontakt, npr. „011/123-456“; ostavi prazno ako ga nema.')
                    ->maxLength(64),
                TextInput::make('sajt')
                    ->label('Sajt')
                    ->helperText('Adresa zvaničnog sajta; bez nje se organizacija ne može objaviti.')
                    ->url()
                    ->required(fn (Get $get): bool => self::statusJeObjavljeno($get('status')))
                    ->validationMessages(['required' => Organizacija::PORUKA_BEZ_SAJTA])
                    ->maxLength(2048),
                Repeater::make('usluge')
                    ->label('Usluge')
                    ->helperText('Šta organizacija nudi, jedna usluga po redu, npr. „Savetovanje“.')
                    ->simple(Textarea::make('usluga')->rows(1)->autosize()->required()->maxLength(255))
                    ->addActionLabel('Dodaj uslugu')
                    ->defaultItems(0)
                    ->reorderable()
                    ->columnSpanFull(),
                Textarea::make('beleska')
                    ->label('Beleška')
                    ->helperText('Samo za tebe, ne vidi se na sajtu; npr. kada su podaci proveravani.')
                    ->autosize()
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Status')
                    ->helperText('Nacrt se ne vidi javno, a Objavljeno se vidi na sajtu; za objavu je potreban sajt.')
                    ->options(StatusObjave::class)
                    ->default(StatusObjave::Nacrt)
                    ->live()
                    ->required(),
            ]);
    }

    private static function statusJeObjavljeno(mixed $status): bool
    {
        $status = $status instanceof StatusObjave ? $status : StatusObjave::tryFrom((string) $status);

        return $status?->jeJavno() ?? false;
    }
}
