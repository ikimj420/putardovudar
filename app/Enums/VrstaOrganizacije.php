<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VrstaOrganizacije: string implements HasLabel
{
    case JavnaInstitucija = 'javna_institucija';
    case Fondacija = 'fondacija';
    case Udruzenje = 'udruzenje';
    case Inicijativa = 'inicijativa';
    case Drugo = 'drugo';

    public function getLabel(): string
    {
        return match ($this) {
            self::JavnaInstitucija => 'Javna institucija',
            self::Fondacija => 'Fondacija',
            self::Udruzenje => 'Udruženje',
            self::Inicijativa => 'Inicijativa',
            self::Drugo => 'Drugo',
        };
    }
}
