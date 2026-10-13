<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Testovi mere kod, ne .env ove mašine: svako podešavanje spoljnih usluga iz okruženja zadaje phpunit.xml. Ključ koji Ivan upiše u .env inače stiže u testove
// (posle prelaska na Claude to je bilo razmišljanje, posle ključa za Jooble ceo dnevni uvoz).
class PodesavanjaIzOkruzenjaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int}> fajl podešavanja, najmanje koliko ima env poziva */
    public static function podesavanja(): array
    {
        return ['Claude' => ['claude', 6], 'Jooble' => ['jooble', 3]];
    }

    #[Test]
    #[DataProvider('podesavanja')]
    public function phpunit_zadaje_svako_podesavanje_iz_okruzenja(string $fajl, int $najmanje): void
    {
        preg_match_all("/env\\('([A-Z_]+)'/", (string) file_get_contents(config_path($fajl.'.php')), $nalazi);
        $nazivi = array_values(array_unique($nalazi[1]));
        preg_match_all('#<env name="([A-Z_]+)"#', (string) file_get_contents(base_path('phpunit.xml')), $zadato);

        $this->assertGreaterThanOrEqual($najmanje, count($nazivi));
        $this->assertSame([], array_values(array_diff($nazivi, $zadato[1])));
        // Ogledalo: merilo vidi podešavanje koje phpunit.xml ne zadaje.
        $this->assertSame(['NEZADATO'], array_values(array_diff([...$nazivi, 'NEZADATO'], $zadato[1])));
    }

    // Bez ključa se Jooble preskače; ključ iz .env ne sme da stigne do testova.
    #[Test]
    public function kljuc_za_jooble_je_u_testovima_prazan(): void
    {
        $this->assertMatchesRegularExpression('#<env name="JOOBLE_KLJUC" value=""/>#', (string) file_get_contents(base_path('phpunit.xml')));
        $this->assertSame('', trim((string) config('jooble.kljuc')));
    }
}
