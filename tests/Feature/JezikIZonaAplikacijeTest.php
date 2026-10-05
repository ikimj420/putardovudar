<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

class JezikIZonaAplikacijeTest extends TestCase
{
    use BezLokalnogOkruzenja;

    protected function setUp(): void
    {
        $this->postaviOkruzenje('APP_LOCALE', null);

        parent::setUp();
    }

    #[Test]
    public function jezik_aplikacije_je_srpski_i_bez_env_fajla(): void
    {
        $this->assertSame('sr', app()->getLocale());
    }

    // Ogledalo: nov .env se pravi kopiranjem primera, pa primer mora da nosi isti jezik.
    #[Test]
    public function env_primer_nosi_srpski_jezik_a_ne_engleski(): void
    {
        $primer = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_LOCALE=sr$/m', $primer);
        $this->assertDoesNotMatchRegularExpression('/^APP_LOCALE=en$/m', $primer);
    }

    #[Test]
    public function zona_aplikacije_je_beograd(): void
    {
        $this->assertSame('Europe/Belgrade', config('app.timezone'));
        $this->assertSame('Europe/Belgrade', date_default_timezone_get());
    }

    // Ogledalo: u 22:30 po UTC beogradski dan je već sledeći, pa UTC daje drugi datum.
    #[Test]
    public function zona_pomera_datum_oko_ponoci_a_utc_ga_ne_bi_pomerio(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 22:30:00', 'UTC'));

        $this->assertSame('06.10.2026.', now()->format('d.m.Y.'));
        $this->assertSame('05.10.2026.', now()->setTimezone('UTC')->format('d.m.Y.'));
    }
}
