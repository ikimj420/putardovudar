<?php

namespace App\Filament\Resources\Predlozi\Tables;

use App\Enums\StatusPredloga;
use App\Models\Predlog;
use App\Support\LinkSajta;
use App\Support\Prikaz;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Spisak su kartice: jedna kolona na telefonu, tri na laptopu. Jedan podatak po redu; prazna vrednost ne crta red.
class PredloziTable
{
    // Dva dugmeta dele širinu kartice (Filament ih na laptopu ne prelama, pa bi dva od po 100 % izašla iz kartice); jedno samo zauzima celu.
    private const SIRINA = ['style' => 'flex: 1 1 0; min-width: 0; justify-content: center;'];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    // Link je veza ka strani prilike; otvara se samo ispravan (http ili https), šta god stajalo u bazi.
                    TextColumn::make('link')
                        ->label('Link')
                        ->weight(FontWeight::Bold)
                        ->url(fn (Predlog $predlog): ?string => LinkSajta::jeIspravan($predlog->link) ? $predlog->link : null)
                        ->openUrlInNewTab()
                        ->searchable()
                        ->wrap()
                        ->extraAttributes(['style' => 'overflow-wrap: anywhere; min-width: 0;']),
                    Split::make([
                        TextColumn::make('status')->label('Status')->badge()->grow(false),
                    ]),
                    self::red('Napomena', 'napomena_prikaz', fn (Predlog $predlog): ?string => $predlog->napomena),
                    self::red('Stiglo', 'datum_prikaz', fn (Predlog $predlog): string => Prikaz::datum($predlog->created_at)),
                ])->space(2),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(StatusPredloga::class),
            ])
            ->contentGrid(['xl' => 3])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                // Dugmad idu ispod kartice, preko cele širine zajedno (pravilo za telefon). Glavna radnja je „Pregledano"; brisanje je sivo, ne najglasnije, i traži potvrdu.
                Action::make('pregledano')
                    ->label('Pregledano')
                    ->button()
                    ->extraAttributes(self::SIRINA)
                    ->visible(fn (?Predlog $record): bool => $record !== null && $record->status === StatusPredloga::Nov)
                    ->authorize('update')
                    ->action(fn (Predlog $record) => $record->update(['status' => StatusPredloga::Pregledan])),
                DeleteAction::make()
                    ->label('Obriši')
                    ->button()
                    ->color('gray')
                    ->extraAttributes(self::SIRINA)
                    ->modalHeading('Obrisati predlog?')
                    ->modalDescription('Predlog se briše zauvek.')
                    ->modalSubmitActionLabel('Obriši'),
            ]);
    }

    /** @param  Closure(Predlog): ?string  $vrednost */
    private static function red(string $oznaka, string $ime, Closure $vrednost): Split
    {
        return Split::make([
            TextColumn::make($ime.'_oznaka')->state($oznaka)->color('gray')->grow(false),
            TextColumn::make($ime)->state($vrednost)->alignEnd()->wrap()->extraAttributes(['style' => 'overflow-wrap: anywhere; min-width: 0;']),
        ])->hidden(fn (Predlog $record): bool => blank($vrednost($record)));
    }
}
