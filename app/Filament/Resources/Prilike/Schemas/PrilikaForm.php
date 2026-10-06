<?php

namespace App\Filament\Resources\Prilike\Schemas;

use App\Enums\KoJeObjavio;
use App\Enums\OsnovObjave;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\Prikaz;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                TextInput::make('objavio')
                    ->label('Objavio')
                    ->helperText('Ollama objavljuje prilike iz uvoza, a Ivan ručno u adminu.')
                    ->formatStateUsing(fn (mixed $state): ?string => KoJeObjavio::tryFrom((string) $state)?->getLabel())
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (?Prilika $record): bool => filled($record?->objavio)),
                Textarea::make('razlog_objave')
                    ->label('Razlog objave')
                    ->helperText('Zašto je prilika objavljena.')
                    ->autosize()
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (?Prilika $record): bool => filled($record?->razlog_objave))
                    ->columnSpanFull(),
                self::predlog('citat_pravila', 'Citat pravila', dug: true)
                    ->helperText('Isečak iz teksta strane koji pokazuje zašto prilika ispunjava pravilo.')
                    ->visible(fn (?Prilika $record): bool => $record?->objavio === KoJeObjavio::Ollama && filled(data_get($record->predlog, 'citat_pravila')))
                    ->columnSpanFull(),
                Section::make('Predlog Ollame')
                    ->description('Ollama je predložila, ali kod nije prihvatio.')
                    ->schema([
                        self::predlog('vrsta', 'Vrsta', fn (mixed $state): string => VrstaPrilike::tryFrom((string) $state)?->getLabel() ?? (string) $state),
                        self::predlog('rok', 'Rok', fn (mixed $state): string => self::datumPredloga((string) $state)),
                        self::predlog('razlog', 'Razlog', dug: true),
                        self::predlog('citat', 'Citat iz teksta', dug: true),
                        self::predlog('osnov', 'Osnov', fn (mixed $state): string => OsnovObjave::tryFrom((string) $state)?->getLabel() ?? (string) $state),
                        self::predlog('citat_pravila', 'Citat pravila', dug: true),
                    ])
                    ->visible(fn (?Prilika $record): bool => $record?->status === StatusPrilike::Nacrt && filled($record->predlog))
                    ->columnSpanFull(),
            ]);
    }

    // Prazna vrednost ne crta red; polje samo prikazuje predlog i ne ulazi u snimanje.
    // Dug tekst ide u polje koje raste, jer se na telefonu jednored seče ustranu.
    private static function predlog(string $kljuc, string $oznaka, ?\Closure $oblik = null, bool $dug = false): TextInput|Textarea
    {
        $polje = $dug ? Textarea::make('predlog.'.$kljuc)->autosize() : TextInput::make('predlog.'.$kljuc);

        return $polje
            ->label($oznaka)
            ->formatStateUsing($oblik ?? fn (mixed $state): string => (string) $state)
            ->disabled()
            ->dehydrated(false)
            ->visible(fn (?Prilika $record): bool => filled(data_get($record?->predlog, $kljuc)));
    }

    private static function datumPredloga(string $iso): string
    {
        // Polje se formatira i kad je sakriveno, pa prazan ili pokvaren datum ne sme da obori stranu.
        if (! CarbonImmutable::canBeCreatedFromFormat($iso, '!Y-m-d')) {
            return $iso;
        }

        return Prikaz::datum(CarbonImmutable::createFromFormat('!Y-m-d', $iso));
    }

    private static function statusZahtevaIzvor(mixed $status): bool
    {
        $status = $status instanceof StatusPrilike ? $status : StatusPrilike::tryFrom((string) $status);

        return $status?->zahtevaIzvor() ?? false;
    }
}
