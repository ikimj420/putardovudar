<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// Paket 100/T5 (Ivan, 05.11.2026.: „sve treba preko Claude-a, zato obrisao Ollamu"): u kodu nema Ollame, ni klase, ni adrese, ni podešavanja. Jedini izuzetak je vrednost „ollama"
// u KoJeObjavio: tako piše u bazi uz stare prilike, pa se ne menja. Komentari se ne broje; kod i tekstovi na stranama da.
class OllamaNijeUKoduTest extends TestCase
{
    private const IZUZETAK = 'app/Enums/KoJeObjavio.php';

    #[Test]
    public function u_kodu_i_na_stranama_nema_ollame_osim_vrednosti_u_bazi(): void
    {
        $koren = dirname(__DIR__, 2);
        $obidjeno = 0;
        $nadjeno = [];

        foreach (['app', 'config', 'database', 'routes', 'resources/views'] as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($koren.'/'.$folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $fajl) {
                $putanja = substr($fajl->getPathname(), strlen($koren) + 1);
                $kod = (string) file_get_contents($fajl->getPathname());

                if (str_ends_with($putanja, '.blade.php')) {
                    $obidjeno++;
                    // Blade komentar {{-- --}} ne važi, tekst na strani da.
                    $this->pogodak((string) preg_replace('/\{\{--.*?--\}\}/s', '', $kod)) && $nadjeno[] = $putanja;
                } elseif ($fajl->getExtension() === 'php') {
                    $obidjeno++;
                    $this->gradiOllamu($kod) && $nadjeno[] = $putanja;
                }
            }
        }

        // Dokaz da je merilo obišlo neprazan skup i da vidi jedino dozvoljeno mesto.
        $this->assertGreaterThan(100, $obidjeno);
        $this->assertContains(self::IZUZETAK, $nadjeno);
        $this->assertSame([self::IZUZETAK], $nadjeno);
    }

    #[Test]
    public function nema_ni_foldera_ni_podesavanja_ollame(): void
    {
        $koren = dirname(__DIR__, 2);

        $this->assertDirectoryDoesNotExist($koren.'/app/Services/Ollama');
        $this->assertFileDoesNotExist($koren.'/config/ollama.php');
        $this->assertDirectoryDoesNotExist($koren.'/tests/Feature/Ollama');
    }

    // Ogledalo: merilo vidi klasu, prostor imena, tekst i podešavanje, a ne vidi komentar.
    #[Test]
    public function merilo_prepoznaje_kod_a_ne_komentar(): void
    {
        $this->assertTrue($this->gradiOllamu('<?php use App\\Services\\Ollama\\Ollama; $a = new Ollama;'));
        $this->assertTrue($this->gradiOllamu("<?php \$a = 'ollama';"));
        $this->assertTrue($this->gradiOllamu("<?php \$a = config('ollama.model');"));
        $this->assertTrue($this->gradiOllamu('<?php $a = env("OLLAMA_ADRESA");'));
        $this->assertTrue($this->gradiOllamu('<?php class OllamaNedostupna {}'));
        $this->assertFalse($this->gradiOllamu("<?php\n// Ollama je izbačena\n/** nekad Ollama */\n\$a = 1;"));
        $this->assertTrue($this->pogodak('<p>Ollama</p>'));
        $this->assertFalse($this->pogodak('<p>Claude</p>'));
    }

    private function pogodak(string $tekst): bool
    {
        return stripos($tekst, 'ollama') !== false;
    }

    private function gradiOllamu(string $kod): bool
    {
        foreach (token_get_all($kod) as $token) {
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) && stripos($token[1], 'ollama') !== false) {
                return true;
            }
        }

        return false;
    }
}
