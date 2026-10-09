<?php

namespace Tests\Unit;

use App\Support\DatumiUTekstu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DatumiUTekstuTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<array{dan: int, mesec: int, godina: int|null}>}> */
    public static function slucajevi(): array
    {
        return [
            'brojevi sa tačkama' => ['Rok je 20.10.2026.', [['dan' => 20, 'mesec' => 10, 'godina' => 2026]]],
            'brojevi sa razmacima' => ['Rok je 5. 11. 2026.', [['dan' => 5, 'mesec' => 11, 'godina' => 2026]]],
            'kose crte' => ['do 20/10/2026', [['dan' => 20, 'mesec' => 10, 'godina' => 2026]]],
            'ISO' => ['rok: 2026-10-20', [['dan' => 20, 'mesec' => 10, 'godina' => 2026]]],
            'mesec rečju sa godinom' => ['do 20. oktobra 2026. godine', [['dan' => 20, 'mesec' => 10, 'godina' => 2026]]],
            'mesec rečju bez godine' => ['do 20. oktobra', [['dan' => 20, 'mesec' => 10, 'godina' => null]]],
            'veliko slovo' => ['Do 3. NOVEMBRA 2026.', [['dan' => 3, 'mesec' => 11, 'godina' => 2026]]],
            'dan i mesec brojem, završna tačka' => ['Prijave do 20.10.', [['dan' => 20, 'mesec' => 10, 'godina' => null]]],
            'dva datuma' => ['od 1. oktobra do 15. oktobra 2026.', [['dan' => 1, 'mesec' => 10, 'godina' => null], ['dan' => 15, 'mesec' => 10, 'godina' => 2026]]],
            'maj, jun i jul se ne mešaju' => ['5. maja, 6. juna i 7. jula', [['dan' => 5, 'mesec' => 5, 'godina' => null], ['dan' => 6, 'mesec' => 6, 'godina' => null], ['dan' => 7, 'mesec' => 7, 'godina' => null]]],
        ];
    }

    /**
     * @param  list<array{dan: int, mesec: int, godina: int|null}>  $ocekivano
     */
    #[Test]
    #[DataProvider('slucajevi')]
    public function nalazi_datume_u_uobicajenim_oblicima(string $tekst, array $ocekivano): void
    {
        $this->assertSame($ocekivano, DatumiUTekstu::izvuci($tekst));
    }

    /** @return array<string, array{0: string}> */
    public static function nisuDatumi(): array
    {
        return [
            'decimalni broj' => ['Plata je 10.5 hiljada'], 'cena' => ['Košta 1.500 dinara'], 'nepostojeći mesec' => ['5.13.2026.'],
            'nepostojeći dan' => ['32. oktobra'], 'bez brojeva' => ['Prijave su u toku.'], 'redni broj' => ['Našao sam 3 prilike.'],
        ];
    }

    #[Test]
    #[DataProvider('nisuDatumi')]
    public function broj_koji_nije_datum_se_ne_racuna(string $tekst): void
    {
        $this->assertSame([], DatumiUTekstu::izvuci($tekst));
    }
}
