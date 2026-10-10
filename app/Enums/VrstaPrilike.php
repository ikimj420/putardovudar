<?php

namespace App\Enums;

use App\Support\PoredjenjeTeksta;
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

    // Vrsta se dokazuje rečju sa strane: njen naziv ili srodna reč (u bilo kom padežu). „Drugo" nema reč, pa se ne može dokazati.
    public function potkrepljenaTekstom(string $tekst): bool
    {
        $uzorak = match ($this) {
            self::Posao => '/\b(?:posao|posla|poslu|poslom|poslovi|poslova|poslovima|poslove|zaposl[a-z]*|radn[a-z]* mest[a-z]*)\b/',
            self::Praksa => '/\b(?:praks(?:a|e|i|u|om)|pripravnic[a-z]*)\b/',
            self::Stipendija => '/\bstipend[a-z]*\b/',
            self::Konkurs => '/\b(?:konkurs[a-z]*|poziv(?:a|u|om|i|e|ima)?)\b/',
            self::Obuka => '/\b(?:obuk[a-z]*|obuci|kurs(?:a|u|om|evi|eve|evima|eva)?|radionic[a-z]*|trening[a-z]*)\b/',
            self::Drugo => null,
        };

        return $uzorak !== null && preg_match($uzorak, PoredjenjeTeksta::normalizuj($tekst)) === 1;
    }
}
