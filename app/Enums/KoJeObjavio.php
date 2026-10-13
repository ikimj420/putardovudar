<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KoJeObjavio: string implements HasLabel
{
    // Vrednost „ollama" je zapisana u bazi i znači „objavio je model" (Ollama ili Claude); admin piše „Model", a koji je model, iz predloga (paket 72).
    case Ollama = 'ollama';
    case Covek = 'covek';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ollama => 'Model',
            self::Covek => 'Ivan',
        };
    }
}
