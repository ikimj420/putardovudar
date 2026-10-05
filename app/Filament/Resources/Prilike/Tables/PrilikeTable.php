<?php

namespace App\Filament\Resources\Prilike\Tables;

use App\Models\Prilika;
use App\Support\Prikaz;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Jedan podatak po redu, oznaka levo i vrednost desno; prazna vrednost ne crta red. Isto na telefonu i na laptopu.
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
                    self::red('Vrsta', TextColumn::make('vrsta')->label('Vrsta')->badge()),
                    self::red('Status', TextColumn::make('status')->label('Status')->badge()),
                    self::red('Rok', TextColumn::make('rok')->label('Rok')->date(Prikaz::DATUM))
                        ->hidden(fn (Prilika $record): bool => $record->rok === null),
                    Split::make([
                        TextColumn::make('oznaka_rok_stalno_otvoren')->state('Rok stalno otvoren')->color('gray'),
                    ])->hidden(fn (Prilika $record): bool => ! $record->rok_stalno_otvoren),
                ])->space(2),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    private static function red(string $oznaka, TextColumn $vrednost): Split
    {
        return Split::make([
            TextColumn::make('oznaka_'.$vrednost->getName())->state($oznaka)->color('gray'),
            $vrednost->alignEnd(),
        ]);
    }
}
