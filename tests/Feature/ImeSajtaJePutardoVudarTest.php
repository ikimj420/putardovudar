<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\Concerns\VidljivTekst;
use Tests\TestCase;

class ImeSajtaJePutardoVudarTest extends TestCase
{
    use BezLokalnogOkruzenja, VidljivTekst;

    #[Test]
    public function ime_aplikacije_je_putardo_vudar_i_bez_env_fajla(): void
    {
        $this->assertSame('Putardo Vudar', config('app.name'));
    }

    #[Test]
    public function naslov_kartice_i_strane_admina_kazu_putardo_vudar_a_ne_laravel(): void
    {
        $this->postaviOkruzenje('ADMIN_PATH', 'a'.bin2hex(random_bytes(6)));
        $this->refreshApplication();

        $html = $this->get('/'.config('admin.path').'/login')->assertOk()->getContent();

        $this->assertStringContainsString('Putardo Vudar', $this->naslovStrane($html));
        $this->assertStringContainsString('Putardo Vudar', $this->vidljivTekst($html));
        $this->assertStringNotContainsString('Laravel', $this->naslovStrane($html));
        $this->assertStringNotContainsString('Laravel', $this->vidljivTekst($html));
    }

    // Ogledalo: nov .env se pravi kopiranjem primera, pa primer mora da nosi isto ime.
    #[Test]
    public function env_primer_nosi_ime_putardo_vudar_a_ne_laravel(): void
    {
        $primer = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_NAME="Putardo Vudar"$/m', $primer);
        $this->assertDoesNotMatchRegularExpression('/^APP_NAME=Laravel$/m', $primer);
    }
}
