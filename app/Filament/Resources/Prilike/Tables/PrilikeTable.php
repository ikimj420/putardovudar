<?php

namespace App\Filament\Resources\Prilike\Tables;

use App\Enums\StatusPrilike;
use App\Models\Prilika;
use App\Support\Prikaz;
use App\Support\PrikazPrilike;
use Closure;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Spisak su kartice: jedna kolona na telefonu, tri na laptopu. Jedan podatak po redu, oznaka levo i vrednost desno;
// prazna vrednost ne crta red.
class PrilikeTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    TextColumn::make('naslov')
                        ->label('Naslov')
                        ->weight(FontWeight::Bold)
                        ->searchable()
                        ->wrap(),
                    Split::make([
                        TextColumn::make('status')->label('Status')->badge()->grow(false),
                        TextColumn::make('vrsta')->label('Vrsta')->badge()->grow(false),
                    ]),
                    self::red('Rok', 'rok_prikaz', fn (Prilika $prilika): string => self::rok($prilika)),
                    self::red('Izvor', 'izvor_prikaz', fn (Prilika $prilika): ?string => PrikazPrilike::nazivIzvora($prilika)),
                    self::red('Objavio', 'objavio_prikaz', fn (Prilika $prilika): ?string => $prilika->objavio?->getLabel()),
                ])->space(2),
            ])
            ->filters([
                // Veza sa početne strane admina vodi ovde, na spisak samo sa nacrtima.
                SelectFilter::make('status')->label('Status')->options(StatusPrilike::class),
            ])
            ->contentGrid(['xl' => 3])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                // Dugme ide ispod kartice preko cele širine (pravilo za telefon); Filament nema opciju za to, a admin nema svoju temu.
                EditAction::make()->label('Uredi')->button()->extraAttributes(['style' => 'width: 100%; justify-content: center;']),
            ]);
    }

    // Isti tekstovi kao na sajtu (PrikazPrilike), a datum kroz Prikaz; oznaka „Rok:“ je ovde u samom redu.
    private static function rok(Prilika $prilika): string
    {
        if ($prilika->rok_stalno_otvoren) {
            return PrikazPrilike::STALNO_OTVORENO;
        }

        return $prilika->rok === null ? PrikazPrilike::BEZ_ROKA : Prikaz::datum($prilika->rok);
    }

    /** @param  Closure(Prilika): ?string  $vrednost */
    private static function red(string $oznaka, string $ime, Closure $vrednost): Split
    {
        return Split::make([
            TextColumn::make($ime.'_oznaka')->state($oznaka)->color('gray')->grow(false),
            TextColumn::make($ime)->state($vrednost)->alignEnd()->wrap(),
        ])->hidden(fn (Prilika $record): bool => blank($vrednost($record)));
    }
}
