<?php

namespace Tests\Feature\Claude;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

// Paket 71/T1: podešavanja Claude-a. Ključ je samo u okruženju (.env, koji se ovde ne čita): primer ga nema, a nijedan fajl projekta ne nosi nešto što liči na ključ.
class ClaudePodesavanjaTest extends TestCase
{
    use BezLokalnogOkruzenja;

    #[Test]
    public function bez_env_fajla_podrazumevana_podesavanja_su_ollama_haiku_i_izmerene_cene(): void
    {
        // phpunit.xml zadaje obradu na Ollami i prazan ključ; da bi se videlo šta stoji u podešavanju, ta tri zadavanja se skidaju.
        foreach (['MODEL_OBRADE', 'ANTHROPIC_API_KEY', 'CLAUDE_MODEL', 'CLAUDE_ADRESA', 'CLAUDE_VREME_CEKANJA'] as $naziv) {
            $this->postaviOkruzenje($naziv, null);
        }
        $this->refreshApplication();

        $this->assertSame('ollama', config('claude.model_obrade'));
        $this->assertNull(config('claude.kljuc'));
        $this->assertSame('claude-haiku-5-5', config('claude.model'));
        $this->assertSame('https://api.anthropic.com', config('claude.adresa'));
        $this->assertSame(120, config('claude.vreme_cekanja'));
        $this->assertSame(12000, config('claude.najvise_znakova'));
        $this->assertNull(config('claude.temperatura'));
        $this->assertNull(config('claude.razmisljanje'));
        $this->assertSame(4000, config('claude.najvise_izlaznih_tokena'));
        $this->assertSame(['claude-haiku-5-5' => ['ulaz' => 0.10, 'izlaz' => 0.50], 'claude-sonnet-5-5' => ['ulaz' => 2.00, 'izlaz' => 10.00]], config('claude.cene'));
    }

    // Isti broj znakova teksta strane kao kod Ollame, da je proba poštena.
    #[Test]
    public function tekst_strane_je_isti_za_oba_modela(): void
    {
        $this->assertSame(config('ollama.najvise_znakova'), config('claude.najvise_znakova'));
    }

    // Ogledalo: nov .env se pravi kopiranjem primera; primer ima prazan ključ i obradu na Ollami.
    #[Test]
    public function env_primer_ima_prazan_kljuc_i_obradu_na_ollami(): void
    {
        $primer = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('#^ANTHROPIC_API_KEY=$#m', $primer);
        $this->assertMatchesRegularExpression('#^MODEL_OBRADE=ollama$#m', $primer);
    }

    #[Test]
    public function vrednost_iz_okruzenja_pobedjuje_podrazumevanu(): void
    {
        $this->postaviOkruzenje('MODEL_OBRADE', 'claude');
        $this->postaviOkruzenje('CLAUDE_MODEL', 'claude-sonnet-5-5');
        $this->postaviOkruzenje('ANTHROPIC_API_KEY', 'probni-kljuc-nije-pravi');
        $this->refreshApplication();

        $this->assertSame('claude', config('claude.model_obrade'));
        $this->assertSame('claude-sonnet-5-5', config('claude.model'));
        $this->assertSame('probni-kljuc-nije-pravi', config('claude.kljuc'));
    }

    // Testovi nikad ne vide ključ iz Ivanovog okruženja: phpunit.xml ga prazni, a obradu vraća na Ollamu.
    #[Test]
    public function phpunit_prazni_kljuc_i_vraca_obradu_na_ollamu(): void
    {
        $xml = (string) file_get_contents(base_path('phpunit.xml'));

        $this->assertMatchesRegularExpression('#<env name="ANTHROPIC_API_KEY" value=""/>#', $xml);
        $this->assertMatchesRegularExpression('#<env name="MODEL_OBRADE" value="ollama"/>#', $xml);
    }

    // Nijedan fajl projekta (osim .env, koji se ne čita i van gita je) nema nešto što liči na Anthropic ključ.
    #[Test]
    public function nijedan_fajl_projekta_ne_nosi_kljuc(): void
    {
        $koren = dirname(__DIR__, 3);
        $obidjeno = 0;
        $nadjeno = [];

        foreach (['app', 'config', 'database', 'resources', 'routes', 'tests'] as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($koren.'/'.$folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $fajl) {
                if (! in_array($fajl->getExtension(), ['php', 'md', 'json', 'txt', 'xml', 'js', 'css', 'html', 'example'], true)) {
                    continue;
                }

                $obidjeno++;

                if (preg_match('/sk-ant-[A-Za-z0-9_-]{10,}/', (string) file_get_contents($fajl->getPathname())) === 1) {
                    $nadjeno[] = substr($fajl->getPathname(), strlen($koren) + 1);
                }
            }
        }

        foreach (['.env.example', 'phpunit.xml', 'composer.json'] as $fajl) {
            $obidjeno++;

            if (preg_match('/sk-ant-[A-Za-z0-9_-]{10,}/', (string) file_get_contents($koren.'/'.$fajl)) === 1) {
                $nadjeno[] = $fajl;
            }
        }

        $this->assertGreaterThan(100, $obidjeno);
        $this->assertSame([], $nadjeno);
        // Ogledalo: merilo vidi nešto što liči na ključ.
        $this->assertSame(1, preg_match('/sk-ant-[A-Za-z0-9_-]{10,}/', 'ANTHROPIC_API_KEY=sk-'.'ant-api03-abcdefghij1234567890'));
    }
}
