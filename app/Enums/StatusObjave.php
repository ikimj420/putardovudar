<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

// Status vodiča i organizacija: nacrt se ne vidi javno, objavljeno se vidi. Prilike imaju svoj status (i arhivirano).
enum StatusObjave: string implements HasColor, HasLabel
{
    case Nacrt = 'nacrt';
    case Objavljeno = 'objavljeno';

    // Jedino mesto koje kaže koji status znači „vidi se na sajtu"; forma i model pitaju ovde.
    public function jeJavno(): bool
    {
        return $this === self::Objavljeno;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nacrt => 'gray',
            self::Objavljeno => 'success',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Nacrt => 'Nacrt',
            self::Objavljeno => 'Objavljeno',
        };
    }
}
