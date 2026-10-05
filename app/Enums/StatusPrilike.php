<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StatusPrilike: string implements HasLabel
{
    case Nacrt = 'nacrt';
    case Objavljeno = 'objavljeno';
    case Arhivirano = 'arhivirano';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nacrt => 'Nacrt',
            self::Objavljeno => 'Objavljeno',
            self::Arhivirano => 'Arhivirano',
        };
    }
}
