<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

class PomocnikPodesavanjaTest extends TestCase
{
    use BezLokalnogOkruzenja;

    #[Test]
    public function bez_env_fajla_podrazumevano_je_dvadeset_pet_sekundi_za_pitanje_pet_sekundi_za_odgovor_i_pet_pitanja_u_minuti(): void
    {
        $this->assertSame(25, config('pomocnik.ukupno_za_pitanje'));
        $this->assertSame(5, config('pomocnik.najmanje_za_odgovor'));
        $this->assertSame(5, config('pomocnik.pitanja_u_minuti'));
    }

    // Ogledalo: nov .env se pravi kopiranjem primera, pa primer mora da nosi ista dva podešavanja.
    #[Test]
    public function env_primer_nosi_ukupno_vreme_i_broj_pitanja(): void
    {
        $primer = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('#^POMOCNIK_UKUPNO_ZA_PITANJE=25$#m', $primer);
        $this->assertMatchesRegularExpression('#^POMOCNIK_PITANJA_U_MINUTI=5$#m', $primer);
    }

    #[Test]
    public function vrednost_iz_okruzenja_pobedjuje_podrazumevanu(): void
    {
        $this->postaviOkruzenje('POMOCNIK_UKUPNO_ZA_PITANJE', '30');
        $this->postaviOkruzenje('POMOCNIK_PITANJA_U_MINUTI', '2');
        $this->refreshApplication();

        $this->assertSame(30, config('pomocnik.ukupno_za_pitanje'));
        $this->assertSame(2, config('pomocnik.pitanja_u_minuti'));
    }

    // Ukupno vreme mora da stane ispod prekida zahteva: PHP na Herdu prekida posle 30 s i računa i čekanje na model (mereno, paket 22).
    #[Test]
    public function podrazumevano_ukupno_vreme_je_ispod_trideset_sekundi_a_ostaje_vremena_za_pitanje_i_odgovor(): void
    {
        $this->assertLessThanOrEqual(25, config('pomocnik.ukupno_za_pitanje'));
        $this->assertGreaterThanOrEqual(2 * config('pomocnik.najmanje_za_odgovor'), config('pomocnik.ukupno_za_pitanje'));
    }
}
