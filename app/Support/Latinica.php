<?php

namespace App\Support;

// Jedino mesto gde se srpska ćirilica pretvara u latinicu. Slovo van srpske azbuke ostaje kakvo je.
final class Latinica
{
    private const MALA = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'đ', 'е' => 'e', 'ж' => 'ž', 'з' => 'z',
        'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'ћ' => 'ć', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
        'ч' => 'č', 'џ' => 'dž', 'ш' => 'š',
    ];

    private const VELIKA = [
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Ђ' => 'Đ', 'Е' => 'E', 'Ж' => 'Ž', 'З' => 'Z',
        'И' => 'I', 'Ј' => 'J', 'К' => 'K', 'Л' => 'L', 'Љ' => 'Lj', 'М' => 'M', 'Н' => 'N', 'Њ' => 'Nj', 'О' => 'O',
        'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'Ћ' => 'Ć', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C',
        'Ч' => 'Č', 'Џ' => 'Dž', 'Ш' => 'Š',
    ];

    // U reči pisanoj samo velikim slovima dvoslovi su veliki (LJUBAV), inače samo prvo slovo (Ljubav).
    private const VELIKI_DVOSLOVI = ['Љ' => 'LJ', 'Њ' => 'NJ', 'Џ' => 'DŽ'];

    public static function izCirilice(string $tekst): string
    {
        $znakovi = mb_str_split($tekst);
        $latinica = '';

        foreach ($znakovi as $mesto => $znak) {
            if (isset(self::VELIKI_DVOSLOVI[$znak]) && (self::jeVeliko($znakovi[$mesto - 1] ?? '') || self::jeVeliko($znakovi[$mesto + 1] ?? ''))) {
                $latinica .= self::VELIKI_DVOSLOVI[$znak];

                continue;
            }

            $latinica .= self::MALA[$znak] ?? self::VELIKA[$znak] ?? $znak;
        }

        return $latinica;
    }

    private static function jeVeliko(string $znak): bool
    {
        return $znak !== '' && preg_match('/^\p{Lu}$/u', $znak) === 1;
    }
}
