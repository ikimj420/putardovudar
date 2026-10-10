<?php

namespace App\Filament\Resources\Organizacije\Tables;

use App\Enums\StatusObjave;
use App\Models\Organizacija;
use Closure;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

// Spisak su kartice: jedna kolona na telefonu, tri na laptopu. Jedan podatak po redu; prazna vrednost ne crta red.
class OrganizacijeTable
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
                        TextColumn::make('vrsta')->label('Vrsta')->badge()->grow(false),
                    ]),
                    self::red('Mesto', 'mesto_prikaz', fn (Organizacija $organizacija): ?string => $organizacija->mesto),
                    self::red('Telefon', 'telefon_prikaz', fn (Organizacija $organizacija): ?string => $organizacija->telefon),
                    self::red('Sajt', 'sajt_prikaz', fn (Organizacija $organizacija): ?string => $organizacija->sajt),
                ])->space(2),
            ])
            ->filters([
                // Veza sa početne strane admina vodi ovde, na spisak samo sa nacrtima.
                SelectFilter::make('status')->label('Status')->options(StatusObjave::class),
            ])
            ->contentGrid(['xl' => 3])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                // Dugme ide ispod kartice preko cele širine (pravilo za telefon); Filament nema opciju za to, a admin nema svoju temu.
                EditAction::make()->label('Uredi')->button()->extraAttributes(['style' => 'width: 100%; justify-content: center;']),
            ]);
    }

    /** @param  Closure(Organizacija): ?string  $vrednost */
    private static function red(string $oznaka, string $ime, Closure $vrednost): Split
    {
        return Split::make([
            TextColumn::make($ime.'_oznaka')->state($oznaka)->color('gray')->grow(false),
            // Adresa sajta nema razmaka, pa bez prelamanja gde god treba izlazi iz kartice na uskoj koloni.
            TextColumn::make($ime)->state($vrednost)->alignEnd()->wrap()->extraAttributes(['style' => 'overflow-wrap: anywhere; min-width: 0;']),
        ])->hidden(fn (Organizacija $record): bool => blank($vrednost($record)));
    }
}
