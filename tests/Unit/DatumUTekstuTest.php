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
            'instrumental drugog meseca' => ['Zaključno sa 20. novembrom 2026.'],
            'instrumental bez godine' => ['Zaključno sa 20. oktobrom'],
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

    /** @return array<string, array{0: int, 1: string}> broj meseca, mesec u instrumentalu (paket 72/T1) */
    public static function mesecUInstrumentalu(): array
    {
        return [
            'januar' => [1, 'januarom'], 'februar' => [2, 'februarom'], 'mart' => [3, 'martom'], 'april' => [4, 'aprilom'], 'maj' => [5, 'majom'], 'maj (majem)' => [5, 'majem'], 'jun' => [6, 'junom'],
            'jul' => [7, 'julom'], 'avgust' => [8, 'avgustom'], 'septembar' => [9, 'septembrom'], 'oktobar' => [10, 'oktobrom'], 'novembar' => [11, 'novembrom'], 'decembar' => [12, 'decembrom'],
        ];
    }

    // Paket 72/T1: strana #62 iz probe 71 piše „zaključno sa 15. oktobrom 2026. godine"; instrumental je isti datum kao genitiv.
    #[Test]
    #[DataProvider('mesecUInstrumentalu')]
    public function mesec_u_instrumentalu_je_isti_datum(int $mesec, string $oblik): void
    {
        $tekst = 'Rok počinje da teče od 1. septembra zaključno sa 15. '.$oblik.' 2026. godine.';

        $this->assertTrue(DatumUTekstu::postoji($tekst, sprintf('2026-%02d-15', $mesec)));
        // Ogledalo: isti tekst ne dokazuje drugi mesec, drugi dan ni drugu godinu.
        $this->assertFalse(DatumUTekstu::postoji($tekst, sprintf('2026-%02d-15', $mesec % 12 + 1)));
        $this->assertFalse(DatumUTekstu::postoji($tekst, sprintf('2026-%02d-16', $mesec)));
        $this->assertFalse(DatumUTekstu::postoji($tekst, sprintf('2027-%02d-15', $mesec)));
    }

    // Paket 73/T2: „zaključno sa 15. majem 2027. godine" je 15.5.2027. (pravilan oblik; „majom" ostaje).
    #[Test]
    public function maj_u_instrumentalu_je_majem(): void
    {
        $this->assertTrue(DatumUTekstu::postoji('Rok počinje da teče od 1. aprila zaključno sa 15. majem 2027. godine.', '2027-05-15'));
        $this->assertTrue(DatumUTekstu::postoji('Zaključno sa 15. majom 2027. godine.', '2027-05-15'));
        // Ogledalo: drugi dan, mesec i godina isto ne prolaze.
        $this->assertFalse(DatumUTekstu::postoji('Zaključno sa 15. majem 2027. godine.', '2027-05-16'));
        $this->assertFalse(DatumUTekstu::postoji('Zaključno sa 15. majem 2027. godine.', '2027-06-15'));
        $this->assertFalse(DatumUTekstu::postoji('Zaključno sa 15. majem 2027. godine.', '2028-05-15'));
        $this->assertSame(14, DatumUTekstu::prviPolozaj('Zaključno sa 15. majem 2027.', 15, 5));
    }

    // Merilo iznad prolazi i kad tabela nema oblik za neki mesec; ovde se broji da ga ima svih dvanaest.
    #[Test]
    public function svih_dvanaest_meseci_ima_oblik_u_instrumentalu(): void
    {
        $this->assertCount(12, DatumUTekstu::MESECI);
        $this->assertCount(13, self::mesecUInstrumentalu());
        $this->assertCount(12, array_unique(array_column(self::mesecUInstrumentalu(), 0)));

        foreach (self::mesecUInstrumentalu() as [$mesec, $oblik]) {
            $this->assertContains($oblik, explode('|', DatumUTekstu::MESECI[$mesec]), 'mesec '.$mesec);
        }

        // Ogledalo: nijedan oblik ne pripada dvama mesecima.
        $oblici = array_merge(...array_map(fn (string $oblici) => explode('|', $oblici), DatumUTekstu::MESECI));
        $this->assertGreaterThanOrEqual(37, count($oblici));
        $this->assertSame($oblici, array_values(array_unique($oblici)));
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
            'ime meseca u instrumentalu' => ['Zaključno sa 20. oktobrom 2026.', 20, 10, 14],
            'instrumental drugog meseca' => ['Zaključno sa 20. novembrom 2026.', 20, 10, null],
        ];
    }

    #[Test]
    #[DataProvider('polozajDatuma')]
    public function prvi_polozaj_dana_i_meseca_u_tekstu(string $tekst, int $dan, int $mesec, ?int $polozaj): void
    {
        $this->assertSame($polozaj, DatumUTekstu::prviPolozaj($tekst, $dan, $mesec));
    }
}
