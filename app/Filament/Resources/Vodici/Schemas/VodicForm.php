<?php

namespace App\Filament\Resources\Vodici\Schemas;

use App\Enums\StatusObjave;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class VodicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('naslov')
                    ->label('Naslov')
                    ->helperText('Naslov vodiča koji se vidi na spisku, npr. „Kako napisati prvi CV“.')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Deo adrese strane; ako ostane prazno, pravi se iz naslova.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Textarea::make('kratak_opis')
                    ->label('Kratak opis')
                    ->helperText('Jedna do dve rečenice za spisak, npr. „Šta staviti u CV kada nemaš radno iskustvo.“')
                    ->required()
                    ->autosize()
                    ->columnSpanFull(),
                Textarea::make('tekst')
                    ->label('Tekst')
                    ->helperText('Ceo vodič, običnim rečima; novi red u tekstu je novi red na strani.')
                    ->required()
                    ->rows(10)
                    ->autosize()
                    ->columnSpanFull(),
                Repeater::make('koraci')
                    ->label('Koraci')
                    ->helperText('Spisak koraka redom, jedan korak po redu, npr. „Napiši kontakt podatke.“')
                    ->simple(Textarea::make('korak')->rows(1)->autosize()->required()->maxLength(500))
                    ->addActionLabel('Dodaj korak')
                    ->reorderable()
                    ->columnSpanFull(),
                Textarea::make('beleska')
                    ->label('Beleška')
                    ->helperText('Samo za tebe, ne vidi se na sajtu; npr. odakle je vodič.')
                    ->autosize()
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Status')
                    ->helperText('Nacrt se ne vidi javno, a Objavljeno se vidi na sajtu.')
                    ->options(StatusObjave::class)
                    ->default(StatusObjave::Nacrt)
                    ->required(),
            ]);
    }
}
