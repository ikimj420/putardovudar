<?php

namespace App\Filament\Resources\Kalendar\Schemas;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Models\KalendarStavka;
use App\Support\Prikaz;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class KalendarForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('naziv')
                    ->label('Naziv')
                    ->helperText('Naziv prilike kakav je bio, npr. „Stipendija Dositeja“.')
                    ->required()
                    ->maxLength(255),
                TextInput::make('ko_daje')
                    ->label('Ko daje')
                    ->helperText('Ko je davao priliku, npr. „Fond za mlade talente“.')
                    ->maxLength(255),
                Select::make('grupe')
                    ->label('Kome je namenjeno')
                    ->helperText('Izaberi jednu ili više grupa kojima je prilika bila namenjena; ostavi prazno ako se ne zna.')
                    ->options(Grupa::class)
                    ->multiple(),
                DatePicker::make('otvoreno_od')
                    ->label('Otvoreno od')
                    ->helperText('Prvi dan kada se moglo prijaviti; ostavi prazno ako se ne zna.')
                    ->native(false)
                    ->displayFormat(Prikaz::DATUM)
                    ->beforeOrEqual('otvoreno_do'),
                DatePicker::make('otvoreno_do')
                    ->label('Otvoreno do')
                    ->helperText('Poslednji dan kada se moglo prijaviti, npr. 26.01.2026.')
                    ->required()
                    ->native(false)
                    ->displayFormat(Prikaz::DATUM),
                TextInput::make('link_izvora')
                    ->label('Link izvora')
                    ->helperText('Adresa zvaničnog izvora; bez nje se stavka ne može objaviti.')
                    ->url()
                    ->required(fn (Get $get): bool => self::statusJeObjavljeno($get('status')))
                    ->validationMessages(['required' => KalendarStavka::PORUKA_BEZ_IZVORA])
                    ->maxLength(2048),
                Textarea::make('napomena')
                    ->label('Napomena')
                    ->helperText('Šta još treba znati o tom roku (vidi se na sajtu), npr. „Prijave su išle preko eUprave.“')
                    ->autosize()
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Status')
                    ->helperText('Nacrt se ne vidi javno, a Objavljeno se vidi u kalendaru na sajtu; za objavu je potreban link izvora.')
                    ->options(StatusObjave::class)
                    ->default(StatusObjave::Nacrt)
                    ->live()
                    ->required(),
            ]);
    }

    private static function statusJeObjavljeno(mixed $status): bool
    {
        $status = $status instanceof StatusObjave ? $status : StatusObjave::tryFrom((string) $status);

        return $status?->jeJavno() ?? false;
    }
}
