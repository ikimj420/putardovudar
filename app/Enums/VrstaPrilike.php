<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VrstaPrilike: string implements HasLabel
{
    case Posao = 'posao';
    case Praksa = 'praksa';
    case Stipendija = 'stipendija';
    case Konkurs = 'konkurs';
    case Obuka = 'obuka';
    case Drugo = 'drugo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Posao => 'Posao',
            self::Praksa => 'Praksa',
            self::Stipendija => 'Stipendija',
            self::Konkurs => 'Konkurs',
            self::Obuka => 'Obuka',
            self::Drugo => 'Drugo',
        };
    }
}
