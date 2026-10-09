<?php

namespace Tests\Unit;

use App\Support\PoredjenjeTeksta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PoredjenjeTekstaTest extends TestCase
{
    #[Test]
    public function normalizuje_slova_dijakritike_pismo_i_razmake(): void
    {
        $this->assertSame('nis cacak durdevo', PoredjenjeTeksta::normalizuj('  Niš,  ČAČAK; Đurđevo!  '));
        $this->assertSame('posao u nisu', PoredjenjeTeksta::normalizuj('Посао у Нишу'));
        $this->assertSame('', PoredjenjeTeksta::normalizuj(' ?! '));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function izrazi(): array
    {
        return [
            'padež: skladištu prema skladište' => ['radnik u skladiste nis', 'skladistu', true],
            'padež: romima prema romi' => ['konkurs za romima', 'romi', true],
            'padež: kurseva prema kurs' => ['kurs engleskog jezika', 'kurseva', true],
            'više reči, sve moraju' => ['radnik u skladistu u nisu', 'radnik skladistu', true],
            'jedna od dve reči fali' => ['radnik u nisu', 'radnik skladistu', false],
            'reč kojoj nema traga' => ['radnik u skladistu', 'knjigovodja', false],
            'početak reči, ne sredina' => ['promocija programa', 'rom', false],
            'prazan izraz' => ['radnik u skladistu', '', false],
        ];
    }

    #[Test]
    #[DataProvider('izrazi')]
    public function izraz_se_poredi_po_pocetku_reci(string $tekst, string $izraz, bool $ocekivano): void
    {
        $this->assertSame($ocekivano, PoredjenjeTeksta::sadrziIzraz($tekst, $izraz));
    }

    #[Test]
    public function stablo_skracuje_samo_duze_reci(): void
    {
        $this->assertSame('skladi', PoredjenjeTeksta::stablo('skladistu'));
        $this->assertSame('skladi', PoredjenjeTeksta::stablo('skladiste'));
        $this->assertSame('cac', PoredjenjeTeksta::stablo('cacak'));
        $this->assertSame('rom', PoredjenjeTeksta::stablo('romi'));
        $this->assertSame('pos', PoredjenjeTeksta::stablo('pos'));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function istaRec(): array
    {
        return [
            'posla i posao' => ['posla', 'posao', true], 'Čačku i Čačak' => ['cacku', 'cacak', true],
            'Kragujevcu i Kragujevac' => ['kragujevcu', 'kragujevac', true], 'Rome i Romi' => ['rome', 'romi', true],
            'različite reči' => ['posao', 'praksa', false], 'roman i romi' => ['roman', 'romi', true],
            'prazno' => ['', 'posao', false],
        ];
    }

    #[Test]
    #[DataProvider('istaRec')]
    public function ista_rec_u_drugom_padezu(string $prva, string $druga, bool $ocekivano): void
    {
        $this->assertSame($ocekivano, PoredjenjeTeksta::istaRec($prva, $druga));
    }
}
