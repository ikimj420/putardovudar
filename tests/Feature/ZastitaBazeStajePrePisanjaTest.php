<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\ZastitaBaze;

// Svaki pokušaj ide u poseban proces sa mrtvim portom 1: i da zaštita zakaže, prava baza ostaje netaknuta.
#[Group('podproces')]
class ZastitaBazeStajePrePisanjaTest extends TestCase
{
    #[Test]
    public function testovi_nad_radnom_bazom_staju_pre_nego_sto_dodirnu_vezu(): void
    {
        $radna = ZastitaBaze::radneBaze(base_path())[0] ?? '';
        $this->assertNotSame('', $radna);

        [$kod, $dnevnik] = $this->pokreniSondu($radna);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('Zaštita baze', $dnevnik);
        $this->assertStringNotContainsString('SQLSTATE', $dnevnik);
    }

    // Ogledalo: sa drugim imenom zaštita propušta, pa sonda stigne do veze i padne tek na mrtvom portu.
    #[Test]
    public function testovi_nad_drugom_bazom_prolaze_zastitu_i_staju_tek_na_vezi(): void
    {
        [$kod, $dnevnik] = $this->pokreniSondu('ogledalo_'.bin2hex(random_bytes(3)));

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('SQLSTATE', $dnevnik);
        $this->assertStringNotContainsString('Zaštita baze', $dnevnik);
    }

    /** @return array{0: int, 1: string} */
    private function pokreniSondu(string $baza): array
    {
        $dnevnik = sys_get_temp_dir().'/sonda-'.bin2hex(random_bytes(4)).'.log';

        $proces = new Process(
            [PHP_BINARY, 'vendor/bin/phpunit', 'tests/Podrska/SondaZaZastitu.php', '--log-events-text', $dnevnik],
            base_path(),
            ['DB_DATABASE' => $baza, 'DB_PORT' => '1'],
        );
        $proces->setTimeout(60)->run();

        $tekst = is_file($dnevnik) ? file_get_contents($dnevnik) : '';
        @unlink($dnevnik);

        $this->assertNotSame('', $tekst, 'Sonda nije ostavila dnevnik: '.$proces->getErrorOutput());

        return [$proces->getExitCode(), $tekst];
    }
}
