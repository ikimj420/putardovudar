<?php

namespace Tests;

use Dotenv\Dotenv;
use RuntimeException;

// Testovi ne smeju da rade nad bazom na kojoj radi sajt. Nema prekidača: prekidač bi postao navika.
final class ZastitaBaze
{
    /** @param  array<string, mixed>  $veza */
    public static function proveriVezu(array $veza, string $folder): void
    {
        if (($veza['url'] ?? '') !== '') {
            throw new RuntimeException('Zaštita baze: veza je zadata adresom (DB_URL), pa se ime testne baze ne može proveriti.');
        }

        self::proveri((string) ($veza['database'] ?? ''), self::radneBaze($folder));
    }

    /** @param  list<string>  $radne */
    public static function proveri(string $testna, array $radne): void
    {
        if ($radne === []) {
            throw new RuntimeException('Zaštita baze: ime radne baze se ne može utvrditi, jer ni .env ni .env.example nemaju DB_DATABASE.');
        }

        if (trim($testna) === '') {
            throw new RuntimeException('Zaštita baze: ime testne baze nije zadato.');
        }

        if (in_array(strtolower($testna), $radne, true)) {
            throw new RuntimeException('Zaštita baze: testovi bi radili nad bazom „'.$testna.'“, a to je baza na kojoj radi sajt. Testovi staju pre bilo kakvog upisa.');
        }
    }

    /** @return list<string> */
    public static function radneBaze(string $folder): array
    {
        $imena = [];

        foreach (['.env', '.env.example'] as $fajl) {
            if (! is_file($folder.'/'.$fajl)) {
                continue;
            }

            $vrednosti = Dotenv::parse(file_get_contents($folder.'/'.$fajl));
            $iUrla = ltrim((string) parse_url($vrednosti['DB_URL'] ?? '', PHP_URL_PATH), '/');

            foreach ([$vrednosti['DB_DATABASE'] ?? '', $iUrla] as $ime) {
                if ($ime !== '') {
                    $imena[] = strtolower($ime);
                }
            }
        }

        return array_values(array_unique($imena));
    }
}
