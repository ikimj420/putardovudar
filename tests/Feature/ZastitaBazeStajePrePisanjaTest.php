<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PokreceDeteProces;
use Tests\TestCase;
use Tests\ZastitaBaze;

// Svaki pokušaj ide u poseban proces sa mrtvim portom 1: i da zaštita zakaže, prava baza ostaje netaknuta.
#[Group('podproces')]
class ZastitaBazeStajePrePisanjaTest extends TestCase
{
    use PokreceDeteProces;

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
        return $this->pokreniPhpunit(['tests/Podrska/SondaZaZastitu.php'], ['DB_DATABASE' => $baza, 'DB_PORT' => '1']);
    }
}
