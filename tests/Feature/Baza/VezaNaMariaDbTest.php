<?php

namespace Tests\Feature\Baza;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class VezaNaMariaDbTest extends BazaTestCase
{
    #[Test]
    public function testovi_su_vezani_na_mariadb(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        $this->assertTrue(self::jeMariaDb(DB::selectOne('select version() as v')->v));
    }

    // Ogledalo: MySQL se predstavlja samo brojem verzije, pa merilo mora da ga odbije.
    #[Test]
    public function merilo_ne_prepoznaje_mysql_kao_mariadb(): void
    {
        $this->assertFalse(self::jeMariaDb('8.0.36'));
        $this->assertTrue(self::jeMariaDb('12.3.2-MariaDB'));
    }

    #[Test]
    public function testovi_rade_nad_putardovudar_test(): void
    {
        $this->assertSame('putardovudar_test', DB::connection()->getDatabaseName());
        $this->assertSame('putardovudar_test', DB::selectOne('select database() as d')->d);
    }

    // Ogledalo: baza kojoj server odgovara nije ona iz primera okruženja, na kojoj radi sajt.
    #[Test]
    public function testovi_ne_rade_nad_radnom_bazom(): void
    {
        $radna = Dotenv::parse(file_get_contents(base_path('.env.example')))['DB_DATABASE'] ?? '';

        $this->assertNotSame('', $radna);
        $this->assertNotSame($radna, DB::selectOne('select database() as d')->d);
    }

    private static function jeMariaDb(string $verzija): bool
    {
        return str_contains($verzija, 'MariaDB');
    }
}
