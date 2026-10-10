<?php

namespace App\Filament\Resources\Vodici\Tables;

use App\Models\Vodic;
use App\Support\Prikaz;
use Closure;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Spisak su kartice: jedna kolona na telefonu, tri na laptopu. Jedan podatak po redu; prazna vrednost ne crta red.
class VodiciTable
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
                    ]),
                    self::red('Koraka', 'koraka_prikaz', fn (Vodic $vodic): ?string => count($vodic->koraci ?? []) > 0 ? (string) count($vodic->koraci ?? []) : null),
                    self::red('Izmenjen', 'izmenjen_prikaz', fn (Vodic $vodic): ?string => $vodic->updated_at === null ? null : Prikaz::datum($vodic->updated_at)),
                ])->space(2),
            ])
            ->contentGrid(['xl' => 3])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                // Dugme ide ispod kartice preko cele širine (pravilo za telefon); Filament nema opciju za to, a admin nema svoju temu.
                EditAction::make()->label('Uredi')->button()->extraAttributes(['style' => 'width: 100%; justify-content: center;']),
            ]);
    }

    /** @param  Closure(Vodic): ?string  $vrednost */
    private static function red(string $oznaka, string $ime, Closure $vrednost): Split
    {
        return Split::make([
            TextColumn::make($ime.'_oznaka')->state($oznaka)->color('gray')->grow(false),
            TextColumn::make($ime)->state($vrednost)->alignEnd()->wrap(),
        ])->hidden(fn (Vodic $record): bool => blank($vrednost($record)));
    }
}
