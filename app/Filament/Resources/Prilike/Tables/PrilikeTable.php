<?php

namespace App\Filament\Resources\Prilike\Tables;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\DugmadNaKartici;
use App\Filament\Resources\Kalendar\KalendarResource;
use App\Models\Prilika;
use App\Services\KalendarIzPrilike;
use App\Support\Prikaz;
use App\Support\PrikazKalendara;
use App\Support\PrikazPrilike;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                        TextColumn::make('rok_prosao_oznaka')->state(PrikazPrilike::ROK_PROSAO)->badge()->color('danger')->grow(false)
                            ->hidden(fn (?Prilika $record): bool => $record === null || ! $record->rokJeProsao()),
                    ]),
                    self::red(PrikazPrilike::KOME, 'grupe_prikaz', fn (Prilika $prilika): ?string => PrikazPrilike::grupe($prilika)),
                    self::red('Rok', 'rok_prikaz', fn (Prilika $prilika): string => self::rok($prilika)),
                    self::red('Izvor', 'izvor_prikaz', fn (Prilika $prilika): ?string => PrikazPrilike::nazivIzvora($prilika)),
                    self::red('Objavio', 'objavio_prikaz', fn (Prilika $prilika): ?string => PrikazPrilike::objavio($prilika)),
                ])->space(2),
            ])
            ->filters([
                // Veza sa početne strane admina vodi ovde, na spisak samo sa nacrtima; ključ „status" ostaje.
                SelectFilter::make('status')->label('Status')->options(StatusPrilike::class),
                SelectFilter::make('naziv_izvora')->label(PrikazPrilike::IZBOR_IZVOR)->options(function (): array {
                    $izvori = Prilika::izvori();

                    return array_combine($izvori, $izvori);
                }),
                SelectFilter::make('vrsta')->label(PrikazPrilike::IZBOR_VRSTA)->options(VrstaPrilike::class),
                // Razlozi su tekstovi koje piše obrada; nudi se samo ono što nacrti u bazi stvarno imaju. Veliko početno slovo je samo prikaz.
                SelectFilter::make('zasto_je_nacrt')->label(PrikazPrilike::IZBOR_ZASTO_NACRT)
                    ->options(fn (): array => collect(Prilika::razloziNacrta())->mapWithKeys(fn (string $razlog): array => [$razlog => mb_strtoupper(mb_substr($razlog, 0, 1)).mb_substr($razlog, 1)])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? self::nacrtZbog($query, (string) $data['value']) : $query),
                // Isti uslov kao oznaka „Rok prošao" na kartici i kao javni spisak: odlučuje ga samo Prilika::rokProsao.
                TernaryFilter::make('rok_prosao')->label(PrikazPrilike::ROK_PROSAO)
                    ->placeholder(PrikazPrilike::IZBOR_SVE)->trueLabel(PrikazPrilike::DA)->falseLabel(PrikazPrilike::NE)
                    ->queries(true: fn (Builder $query): Builder => self::rokProsao($query, true), false: fn (Builder $query): Builder => self::rokProsao($query, false), blank: fn (Builder $query): Builder => $query),
            ])
            // Filament upit ubacuje po imenu parametra: mora da se zove $query, inače dobija prazan Builder.
            ->modifyQueryUsing(fn (Builder $query) => Prilika::saOznakomRoka($query))
            ->contentGrid(['xl' => 3])
            ->defaultSort('created_at', 'desc')
            // Masovno se arhiviraju samo nacrti: kartica druge prilike nema polje za izbor (a model ionako ne arhivira šta nije nacrt). Brisanja nema.
            ->checkIfRecordIsSelectableUsing(fn (Prilika $record): bool => $record->jeNacrt())
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('arhiviraj')
                        ->label(PrikazPrilike::ARHIVIRAJ)
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading(PrikazPrilike::ARHIVIRAJ_NASLOV)
                        ->modalDescription(PrikazPrilike::ARHIVIRAJ_OPIS)
                        ->modalSubmitActionLabel(PrikazPrilike::ARHIVIRAJ)
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $arhivirano = $records->filter(fn (Prilika $prilika): bool => $prilika->arhiviraj())->count();

                            Notification::make()->title(PrikazPrilike::ARHIVIRANO.$arhivirano.'.')->success()->send();
                        }),
                ]),
            ])
            ->recordActions([
                // Dugme ide ispod kartice preko cele širine (pravilo za telefon); Filament nema opciju za to, a admin nema svoju temu.
                EditAction::make()->label('Uredi')->button()->extraAttributes(DugmadNaKartici::SIRINA),
                // Samo kad je rok prošao, i samo ko sme da dodaje u kalendar (brava na radnji koja piše). Stavka je nacrt popunjen iz prilike.
                Action::make('dodajUKalendar')
                    ->label(PrikazKalendara::DODAJ)
                    ->button()
                    ->color('gray')
                    ->extraAttributes(DugmadNaKartici::SIRINA)
                    ->visible(fn (?Prilika $record): bool => $record !== null && $record->rokJeProsao() && KalendarResource::canCreate())
                    ->authorize(fn (): bool => KalendarResource::canCreate())
                    ->action(function (Prilika $record, Action $action): void {
                        $stavka = app(KalendarIzPrilike::class)->dodaj($record);

                        Notification::make()->title($stavka->wasRecentlyCreated ? PrikazKalendara::DODATO : PrikazKalendara::VEC_JE)->success()->send();

                        $action->redirect(KalendarResource::getUrl('edit', ['record' => $stavka]));
                    }),
            ]);
    }

    /**
     * @param  Builder<Prilika>  $query
     * @return Builder<Prilika>
     */
    private static function nacrtZbog(Builder $query, string $razlog): Builder
    {
        return $query->nacrtZbog($razlog);
    }

    /**
     * @param  Builder<Prilika>  $query
     * @return Builder<Prilika>
     */
    private static function rokProsao(Builder $query, bool $prosao): Builder
    {
        return $prosao ? $query->rokProsao() : $query->rokNijeProsao();
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
