<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StatusPrilike: string implements HasLabel
{
    case Nacrt = 'nacrt';
    case Objavljeno = 'objavljeno';
    case Arhivirano = 'arhivirano';

    // Jedino mesto koje kaže koji status traži izvor; model ga proverava, a forma čita odavde.
    public function zahtevaIzvor(): bool
    {
        return $this === self::Objavljeno;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Nacrt => 'Nacrt',
            self::Objavljeno => 'Objavljeno',
            self::Arhivirano => 'Arhivirano',
        };
    }
}
