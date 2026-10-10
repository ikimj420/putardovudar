<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

// Kome je prilika namenjena. Redosled slučajeva je redosled na ekranu i u zapisu; jedno mesto za nazive.
enum Grupa: string implements HasLabel
{
    case Osnovci = 'osnovci';
    case Srednjoskolci = 'srednjoskolci';
    case Studenti = 'studenti';
    case MasterDoktorski = 'master-doktorski';
    case NezaposleniMladi = 'nezaposleni-mladi';
    case Povratnici = 'povratnici';
    case Organizacije = 'organizacije';
    case Romi = 'romi';

    public function getLabel(): string
    {
        return match ($this) {
            self::Osnovci => 'Osnovci',
            self::Srednjoskolci => 'Srednjoškolci',
            self::Studenti => 'Studenti',
            self::MasterDoktorski => 'Master i doktorski studenti',
            self::NezaposleniMladi => 'Nezaposleni mladi',
            self::Povratnici => 'Povratnici iz inostranstva',
            self::Organizacije => 'Organizacije',
            self::Romi => 'Romi i Romkinje',
        };
    }

    /**
     * Vrednosti iz zapisa ili iz modela u grupe, u redosledu enuma, bez ponavljanja; šta nije grupa, ispada.
     *
     * @param  array<mixed>|null  $vrednosti
     * @return list<self>
     */
    public static function izVrednosti(?array $vrednosti): array
    {
        $trazene = (array) $vrednosti;

        return array_values(array_filter(self::cases(), fn (self $grupa) => in_array($grupa->value, $trazene, true)));
    }
}
