<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

// Paket 79: granica slanja predloga je podešavanje okruženja, kao kod pitanja pomoćniku.
class PredloziPodesavanjaTest extends TestCase
{
    use BezLokalnogOkruzenja;

    #[Test]
    public function bez_env_fajla_podrazumevano_su_tri_predloga_u_minuti(): void
    {
        $this->assertSame(3, config('predlozi.slanja_u_minuti'));
    }

    // Ogledalo: nov .env se pravi kopiranjem primera, pa primer mora da nosi isto podešavanje.
    #[Test]
    public function env_primer_nosi_broj_predloga_u_minuti(): void
    {
        $this->assertMatchesRegularExpression('#^PREDLOZI_SLANJA_U_MINUTI=3$#m', file_get_contents(base_path('.env.example')));
    }

    #[Test]
    public function vrednost_iz_okruzenja_pobedjuje_podrazumevanu(): void
    {
        $this->postaviOkruzenje('PREDLOZI_SLANJA_U_MINUTI', '1');
        $this->refreshApplication();

        $this->assertSame(1, config('predlozi.slanja_u_minuti'));
    }
}
