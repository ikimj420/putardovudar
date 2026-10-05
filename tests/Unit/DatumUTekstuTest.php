<?php

namespace Tests\Unit;

use App\Support\DatumUTekstu;
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
}
