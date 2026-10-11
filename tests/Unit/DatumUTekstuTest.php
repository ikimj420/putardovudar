<?php

namespace Tests\Unit;

use App\Support\DatumUTekstu;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DatumUTekstuTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function oblici(): array
    {
        return [
            'tačke' => ['Rok je 20.10.2026.'],
            'tačke sa razmacima' => ['Rok je 20. 10. 2026.'],
            'bez završne tačke' => ['Rok je 20.10.2026 do podne'],
            'sa vodećom nulom' => ['Rok je 05.10.2026.'],
            'kose crte' => ['Rok je 20/10/2026'],
            'crtice' => ['Rok je 20-10-2026'],
            'ISO' => ['Rok: 2026-10-20'],
            'mesec genitiv' => ['Rok je 20. oktobra 2026. godine'],
            'mesec nominativ' => ['Rok je 20 oktobar 2026'],
            'veliko slovo' => ['Rok je 20. OKTOBRA 2026.'],
            'zarez posle meseca' => ['Rok je 20. oktobra, 2026.'],
            'usred rečenice' => ['Prijave se primaju do 20.10.2026. u 12 časova.'],
        ];
    }

    #[Test]
    #[DataProvider('oblici')]
    public function prepoznaje_datum_u_uobicajenim_oblicima(string $tekst): void
    {
        $datum = str_contains($tekst, '05.10') ? '2026-10-05' : '2026-10-20';

        $this->assertTrue(DatumUTekstu::postoji($tekst, $datum));
    }

    /** @return array<string, array{0: string}> */
    public static function nijeTaj(): array
    {
        return [
            'drugi dan' => ['Rok je 21.10.2026.'],
            'drugi mesec' => ['Rok je 20.11.2026.'],
            'druga godina' => ['Rok je 20.10.2027.'],
            'bez godine' => ['Rok je 20. oktobra'],
            'dan zalepljen za veći broj' => ['Rok je 120.10.2026.'],
            'godina zalepljena za veći broj' => ['Rok je 20.10.20261.'],
            'mesec napisan drugačije' => ['Rok je 20. novembra 2026.'],
            'nema datuma' => ['Rok za prijavu je uskoro.'],
            'prazno' => [''],
        ];
    }

    // Ogledalo: isti tekst sa drugim datumom ne prolazi.
    #[Test]
    #[DataProvider('nijeTaj')]
    public function ne_prepoznaje_drugi_datum_ni_datum_bez_godine(string $tekst): void
    {
        $this->assertFalse(DatumUTekstu::postoji($tekst, '2026-10-20'));
    }

    #[Test]
    public function nepostojeci_ili_pokvaren_datum_se_odbija(): void
    {
        $this->assertFalse(DatumUTekstu::postoji('Rok je 31.02.2026.', '2026-02-31'));
        $this->assertFalse(DatumUTekstu::postoji('Rok je 20.10.2026.', '20.10.2026'));
        $this->assertFalse(DatumUTekstu::postoji('Rok je 20.10.2026.', ''));
    }

    /** @return array<string, array{0: int, 1: int, 2: string, 3: string|null}> dan, mesec, posle datuma, prvi takav dan */
    public static function prviDanPosle(): array
    {
        return [
            'u istoj godini' => [26, 10, '2026-09-20', '2026-10-26'],
            'sledeće godine' => [26, 1, '2026-11-20', '2027-01-26'],
            'isti dan važi' => [26, 10, '2026-10-26', '2026-10-26'],
            'dan posle je već sledeća godina' => [26, 10, '2026-10-27', '2027-10-26'],
            'početak godine' => [1, 1, '2026-01-01', '2026-01-01'],
            'kraj godine' => [31, 12, '2026-12-31', '2026-12-31'],
            'prestupni dan čeka prestupnu godinu' => [29, 2, '2026-03-01', '2028-02-29'],
            'prestupni dan u prestupnoj godini' => [29, 2, '2028-01-10', '2028-02-29'],
            'preko 2100 koja nije prestupna' => [29, 2, '2097-03-01', '2104-02-29'],
            'dan koji ne postoji' => [31, 4, '2026-01-01', null],
        ];
    }

    #[Test]
    #[DataProvider('prviDanPosle')]
    public function prvi_dan_posle_datuma_objave(int $dan, int $mesec, string $posle, ?string $ocekivano): void
    {
        $datum = DatumUTekstu::prviPosle($dan, $mesec, CarbonImmutable::parse($posle));

        $this->assertSame($ocekivano, $datum?->toDateString());
    }

    /** @return array<string, array{0: string, 1: int, 2: int, 3: int|null}> tekst, dan, mesec, bajt gde piše (ili null) */
    public static function polozajDatuma(): array
    {
        return [
            'ime meseca' => ['Rok je 20. oktobra 2026.', 20, 10, 7],
            'ime meseca bez godine' => ['Do 12. oktobra', 12, 10, 3],
            'brojevi' => ['Rok: 20.10.2026.', 20, 10, 5],
            'brojevi sa razmakom' => ['Rok: 20. 10. 2026.', 20, 10, 5],
            'nula ispred' => ['Rok: 05.10.2026.', 5, 10, 5],
            'brojevi pre imena meseca' => ['Rok je 20.10.2026. a nekad i 20. oktobra', 20, 10, 7],
            'ime meseca pre brojeva' => ['Do 20. oktobra, a kasnije 20.10.2026.', 20, 10, 3],
            'drugi datum u tekstu' => ['Od 15. oktobra do 20. oktobra', 20, 10, 18],
            'drugi mesec' => ['Rok je 20. novembra', 20, 10, null],
            'dan koji je deo većeg broja' => ['Rok je 120. oktobra', 20, 10, null],
            'ćirilica se ne pretvara ovde' => ['Рок је 20. октобра', 20, 10, null],
            'ima i veliko slovo' => ['Rok je 20. Oktobra', 20, 10, 7],
        ];
    }

    #[Test]
    #[DataProvider('polozajDatuma')]
    public function prvi_polozaj_dana_i_meseca_u_tekstu(string $tekst, int $dan, int $mesec, ?int $polozaj): void
    {
        $this->assertSame($polozaj, DatumUTekstu::prviPolozaj($tekst, $dan, $mesec));
    }
}
