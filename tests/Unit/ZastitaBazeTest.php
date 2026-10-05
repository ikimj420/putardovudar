<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\ZastitaBaze;

class ZastitaBazeTest extends TestCase
{
    #[Test]
    public function testna_baza_istog_imena_kao_radna_se_odbija(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Zaštita baze');

        ZastitaBaze::proveri('putardovudar', ['putardovudar']);
    }

    #[Test]
    public function veliko_slovo_ne_pravi_od_radne_baze_drugu(): void
    {
        $this->expectException(RuntimeException::class);

        ZastitaBaze::proveri('PutardoVudar', ['putardovudar']);
    }

    // Ogledalo: da zaštita odbija sve, testovi nikad ne bi prošli.
    #[Test]
    public function testna_baza_drugog_imena_prolazi(): void
    {
        ZastitaBaze::proveri('putardovudar_test', ['putardovudar']);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function nezadato_ime_testne_baze_se_odbija(): void
    {
        $this->expectException(RuntimeException::class);

        ZastitaBaze::proveri('  ', ['putardovudar']);
    }

    #[Test]
    public function bez_poznate_radne_baze_se_staje_a_ne_pretpostavlja(): void
    {
        $this->expectException(RuntimeException::class);

        ZastitaBaze::proveri('putardovudar_test', []);
    }

    #[Test]
    public function veza_zadata_adresom_se_odbija_jer_se_ime_ne_vidi(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_URL');

        ZastitaBaze::proveriVezu(['url' => 'mysql://root@127.0.0.1:3307/putardovudar_test', 'database' => 'putardovudar_test'], dirname(__DIR__, 2));
    }

    #[Test]
    public function radne_baze_se_citaju_iz_env_i_env_primera_i_adrese(): void
    {
        $folder = sys_get_temp_dir().'/zastita-baze-'.bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents($folder.'/.env', "DB_DATABASE=Prva\n");
        file_put_contents($folder.'/.env.example', "DB_DATABASE=druga\nDB_URL=mysql://root@127.0.0.1:3307/treca\n");

        try {
            $this->assertSame(['prva', 'druga', 'treca'], ZastitaBaze::radneBaze($folder));
        } finally {
            unlink($folder.'/.env');
            unlink($folder.'/.env.example');
            rmdir($folder);
        }
    }

    // Merilo koje čita fajlove mora prvo da dokaže da je našlo nešto: ime iz primera okruženja ovog projekta.
    #[Test]
    public function radne_baze_ovog_projekta_nisu_prazan_skup(): void
    {
        $iPrimera = Dotenv::parse(file_get_contents(dirname(__DIR__, 2).'/.env.example'))['DB_DATABASE'] ?? '';
        $radne = ZastitaBaze::radneBaze(dirname(__DIR__, 2));

        $this->assertNotSame('', $iPrimera);
        $this->assertContains(strtolower($iPrimera), $radne);
    }
}
