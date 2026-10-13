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
            'mesec u instrumentalu sa godinom' => ['zaključno sa 15. oktobrom 2026. godine', [['dan' => 15, 'mesec' => 10, 'godina' => 2026]]],
            'dva datuma, drugi u instrumentalu' => ['od 1. oktobra zaključno sa 15. oktobrom 2026.', [['dan' => 1, 'mesec' => 10, 'godina' => null], ['dan' => 15, 'mesec' => 10, 'godina' => 2026]]],
            'instrumental svih meseci sa „-brom"' => ['5. septembrom, 6. novembrom i 7. decembrom', [['dan' => 5, 'mesec' => 9, 'godina' => null], ['dan' => 6, 'mesec' => 11, 'godina' => null], ['dan' => 7, 'mesec' => 12, 'godina' => null]]],
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

    /** @return array<string, array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int|null}>}> tekst, pojave kao [od, do, dan·mesec, godina] */
    public static function pojaveSaMestom(): array
    {
        return [
            'ime meseca sa godinom' => ['Rok je 20. oktobra 2026.', [[7, 23, 2010, 2026]]],
            'brojevi' => ['Rok: 20.10.2026.', [[5, 15, 2010, 2026]]],
            'iso' => ['do 2026-10-20 u podne', [[3, 13, 2010, 2026]]],
            'dva datuma redom' => ['Od 28. septembra do 12. oktobra', [[3, 16, 2809, null], [20, 31, 1210, null]]],
            'bez godine sa tačkama' => ['Rok je 20.10. u podne', [[7, 13, 2010, null]]],
            'veliko slovo' => ['Rok je 20. Oktobra', [[7, 18, 2010, null]]],
            'datum sa razmacima se ne cepa na dva' => ['Rok 5. 10. 2026. godine', [[4, 15, 510, 2026]]],
            'instrumental sa godinom' => ['Zaključno sa 15. oktobrom 2026.', [[14, 31, 1510, 2026]]],
            'nema datuma' => ['Prijave su u toku, 5 mesta.', []],
        ];
    }

    /** @param  list<array{0: int, 1: int, 2: int, 3: int|null}>  $ocekivano */
    #[Test]
    #[DataProvider('pojaveSaMestom')]
    public function pojave_imaju_mesto_u_tekstu_redom(string $tekst, array $ocekivano): void
    {
        $stvarno = array_map(fn (array $p) => [$p['od'], $p['do'], $p['dan'] * 100 + $p['mesec'], $p['godina']], DatumiUTekstu::pojave($tekst));

        $this->assertSame($ocekivano, $stvarno, $tekst);
    }

    // Isti datumi kao izvuci, bez ponavljanja unutar jednog.
    #[Test]
    public function pojave_i_izvuci_nalaze_iste_datume(): void
    {
        foreach (['Rok je 20. oktobra 2026.', 'Od 28. septembra do 12. oktobra', 'Rok: 20.10.2026. a 5.11.', 'Do 2026-10-20', 'Cena 10.5 hiljada, 32. oktobra'] as $tekst) {
            $izPojava = array_map(fn (array $p) => ['dan' => $p['dan'], 'mesec' => $p['mesec'], 'godina' => $p['godina']], DatumiUTekstu::pojave($tekst));

            $this->assertEqualsCanonicalizing(DatumiUTekstu::izvuci($tekst), $izPojava, $tekst);
        }
    }
}
