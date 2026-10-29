<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

// Predlog korisnika je nov dok ga niko nije pogledao, pa pregledan; u prvi korak ide sam, a u drugi samo radnja „Pregledano" u adminu.
enum StatusPredloga: string implements HasColor, HasLabel
{
    case Nov = 'nov';
    case Pregledan = 'pregledan';

    public function getColor(): string
    {
        return match ($this) {
            self::Nov => 'primary',
            self::Pregledan => 'gray',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Nov => 'Nov',
            self::Pregledan => 'Pregledan',
        };
    }
}
