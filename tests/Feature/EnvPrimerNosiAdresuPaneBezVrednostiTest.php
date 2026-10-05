<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnvPrimerNosiAdresuPaneBezVrednostiTest extends TestCase
{
    #[Test]
    public function env_primer_ima_adresu_pane_bez_vrednosti_i_recenicu_iznad(): void
    {
        $this->assertTrue($this->imaPraznuAdresuSaRecenicom(file_get_contents(base_path('.env.example'))));
    }

    // Ogledalo: isto merilo mora da odbije primer sa upisanom vrednošću i primer bez objašnjenja.
    #[Test]
    public function merilo_odbija_vrednost_u_primeru_i_primer_bez_objasnjenja(): void
    {
        $this->assertFalse($this->imaPraznuAdresuSaRecenicom("# Kad ADMIN_PATH fali, panela nema.\nADMIN_PATH=tajna\n"));
        $this->assertFalse($this->imaPraznuAdresuSaRecenicom("APP_URL=x\nADMIN_PATH=\n"));
    }

    private function imaPraznuAdresuSaRecenicom(string $sadrzaj): bool
    {
        $redovi = explode("\n", $sadrzaj);
        $mesto = array_search('ADMIN_PATH=', $redovi, true);

        if ($mesto === false || $mesto === 0) {
            return false;
        }

        $iznad = $redovi[$mesto - 1];

        return str_starts_with($iznad, '# ')
            && str_contains($iznad, 'fali')
            && str_ends_with($iznad, '.')
            && substr_count($iznad, '. ') === 0;
    }
}
