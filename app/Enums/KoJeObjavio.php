<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KoJeObjavio: string implements HasLabel
{
    case Ollama = 'ollama';
    case Covek = 'covek';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ollama => 'Ollama',
            self::Covek => 'Ivan',
        };
    }
}
