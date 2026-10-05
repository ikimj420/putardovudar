<?php

namespace Tests\Feature\Ollama;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

class OllamaPodesavanjaTest extends TestCase
{
    use BezLokalnogOkruzenja;

    #[Test]
    public function bez_env_fajla_podrazumevana_podesavanja_su_lokalna_ollama_i_model_koji_je_izmeren(): void
    {
        $this->assertSame('http://127.0.0.1:11434', config('ollama.adresa'));
        $this->assertSame('qwen2.5:7b', config('ollama.model'));
        $this->assertSame(120, config('ollama.vreme_cekanja'));
        $this->assertSame(8192, config('ollama.kontekst'));
    }

    // Ogledalo: nov .env se pravi kopiranjem primera, pa primer mora da ima ista tri podešavanja.
    #[Test]
    public function env_primer_nosi_tri_podesavanja_ollame(): void
    {
        $primer = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('#^OLLAMA_ADRESA=http://127\.0\.0\.1:11434$#m', $primer);
        $this->assertMatchesRegularExpression('#^OLLAMA_MODEL=qwen2\.5:7b$#m', $primer);
        $this->assertMatchesRegularExpression('#^OLLAMA_VREME_CEKANJA=120$#m', $primer);
    }

    #[Test]
    public function vrednost_iz_okruzenja_pobedjuje_podrazumevanu(): void
    {
        $this->postaviOkruzenje('OLLAMA_MODEL', 'drugi:3b');
        $this->postaviOkruzenje('OLLAMA_VREME_CEKANJA', '15');
        $this->refreshApplication();

        $this->assertSame('drugi:3b', config('ollama.model'));
        $this->assertSame(15, config('ollama.vreme_cekanja'));
    }
}
