<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KoJeObjavio: string implements HasLabel
{
    // Vrednost „ollama" je zapisana u bazi i znači „objavio je model" (nekad Ollama, sad Claude); stari zapisi je zadržavaju, pa se vrednost ne menja. Admin piše „Model", a koji, iz predloga (paket 72, 100).
    case Model = 'ollama';
    case Covek = 'covek';

    public function getLabel(): string
    {
        return match ($this) {
            self::Model => 'Model',
            self::Covek => 'Ivan',
        };
    }
}
