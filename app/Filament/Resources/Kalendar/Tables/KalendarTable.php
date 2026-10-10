<?php

namespace App\Filament\Resources\Kalendar\Tables;

use App\Enums\StatusObjave;
use App\Models\KalendarStavka;
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

// Spisak su kartice: jedna kolona na telefonu, tri na laptopu. Jedan podatak po redu; prazna vrednost ne crta red.
class KalendarTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Stack::make([
                    TextColumn::make('naziv')
                        ->label('Naziv')
                        ->weight(FontWeight::Bold)
                        ->searchable()
                        ->wrap(),
                    Split::make([
                        TextColumn::make('status')->label('Status')->badge()->grow(false),
                    ]),
                    self::red('Ko daje', 'ko_daje_prikaz', fn (KalendarStavka $stavka): ?string => $stavka->ko_daje),
                    self::red('Kome je namenjeno', 'grupe_prikaz', fn (KalendarStavka $stavka): ?string => PrikazPrilike::nazivi($stavka->izabraneGrupe())),
                    self::red('Otvoreno', 'otvoreno_prikaz', fn (KalendarStavka $stavka): string => ($stavka->otvoreno_od === null ? '' : Prikaz::datum($stavka->otvoreno_od).' – ').Prikaz::datum($stavka->otvoreno_do)),
                    self::red('Izvor', 'izvor_prikaz', fn (KalendarStavka $stavka): ?string => $stavka->link_izvora),
                ])->space(2),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(StatusObjave::class),
            ])
            ->contentGrid(['xl' => 3])
            ->defaultSort('otvoreno_do', 'desc')
            ->recordActions([
                // Dugme ide ispod kartice preko cele širine (pravilo za telefon); Filament nema opciju za to, a admin nema svoju temu.
                EditAction::make()->label('Uredi')->button()->extraAttributes(['style' => 'width: 100%; justify-content: center;']),
            ]);
    }

    /** @param  Closure(KalendarStavka): ?string  $vrednost */
    private static function red(string $oznaka, string $ime, Closure $vrednost): Split
    {
        return Split::make([
            TextColumn::make($ime.'_oznaka')->state($oznaka)->color('gray')->grow(false),
            // Link izvora nema razmaka, pa bez prelamanja gde god treba izlazi iz kartice na uskoj koloni.
            TextColumn::make($ime)->state($vrednost)->alignEnd()->wrap()->extraAttributes(['style' => 'overflow-wrap: anywhere; min-width: 0;']),
        ])->hidden(fn (KalendarStavka $record): bool => blank($vrednost($record)));
    }
}
