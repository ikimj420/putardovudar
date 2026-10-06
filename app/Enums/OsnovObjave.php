<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

// Koji deo Ivanovog pravila za objavu model tvrdi da važi; kod to priznaje tek kad citat sa strane sadrži odgovarajuću reč.
enum OsnovObjave: string implements HasLabel
{
    case Romi = 'romi';
    case Srbija = 'srbija';

    public function getLabel(): string
    {
        return match ($this) {
            self::Romi => 'Romi',
            self::Srbija => 'Srbija',
        };
    }

    // Reč u bilo kom padežu; „roman", „romantika" i „promocija" ne prolaze jer iza ili ispred ne sme biti slovo.
    public function potkrepljuje(string $citat): bool
    {
        $uzorak = match ($this) {
            self::Romi => '~(?<!\p{L})rom(?:a|i|e|u|om|ima|kinj\p{L}*|sk\p{L}*)?(?!\p{L})~u',
            self::Srbija => '~(?<!\p{L})srbij(?:a|e|i|u|o|om)(?!\p{L})~u',
        };

        return preg_match($uzorak, mb_strtolower($citat)) === 1;
    }
}
