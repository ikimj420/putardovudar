<?php

namespace App\Filament\Resources\Sabloni\Schemas;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use App\Support\SklapanjeSablona;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SablonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('naslov')
                    ->label('Naslov')
                    ->helperText('Naslov šablona koji se vidi na spisku, npr. „Molba“.')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Deo adrese strane; ako ostane prazno, pravi se iz naslova.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Textarea::make('objasnjenje')
                    ->label('Objašnjenje')
                    ->helperText('Jedna do dve rečenice za spisak, npr. „Za dopis u kojem nešto tražiš od ustanove.“')
                    ->required()
                    ->autosize()
                    ->columnSpanFull(),
                Repeater::make('polja')
                    ->label('Polja')
                    ->helperText('Šta čovek popunjava, redom; svako polje ima ključ koji u tekstu stoji u zagradama, npr. {ime}.')
                    ->schema([
                        TextInput::make('kljuc')
                            ->label('Ključ')
                            ->helperText('Mala slova bez razmaka, npr. ime ili kome_pisem; isti ključ stoji u tekstu kao {ime}.')
                            ->required()
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->distinct()
                            ->maxLength(40),
                        TextInput::make('oznaka')
                            ->label('Natpis polja')
                            ->helperText('Šta piše iznad polja, npr. „Ime i prezime“.')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('objasnjenje')
                            ->label('Objašnjenje polja')
                            ->helperText('Jedna rečenica ispod natpisa, npr. „Tvoje ime i prezime.“')
                            ->maxLength(200),
                        Toggle::make('dugo')
                            ->label('Više redova')
                            ->helperText('Uključi za duži tekst (polje raste); isključeno je polje u jednom redu.'),
                    ])
                    ->itemLabel(fn (array $state): ?string => $state['oznaka'] ?? null)
                    ->addActionLabel('Dodaj polje')
                    ->defaultItems(0)
                    ->reorderable()
                    ->columns(1)
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('tekst')
                    ->label('Tekst')
                    ->helperText('Opšti oblik dopisa; mesto za polje je ključ u velikim zagradama, npr. {ime}, i svako mesto mora da ima svoje polje. Gde reč zavisi od roda, piši oba oblika ovako: {radio/radila}. Novi red je novi red na strani.')
                    ->required()
                    ->rows(12)
                    ->autosize()
                    ->rules([
                        fn (Get $get): Closure => function (string $atribut, mixed $vrednost, Closure $greska) use ($get): void {
                            $kljucevi = array_map(fn (mixed $polje) => is_array($polje) ? ($polje['kljuc'] ?? null) : null, (array) $get('polja'));
                            $nepoznata = array_values(array_diff(SklapanjeSablona::kljuceUTekstu((string) $vrednost), $kljucevi));

                            if ($nepoznata !== []) {
                                $greska(Sablon::PORUKA_NEPOZNATO_POLJE.'{'.$nepoznata[0].'}');
                            }
                        },
                    ])
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
