<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class OllamaSeZoveSamoIzJedneKlaseTest extends TestCase
{
    private const JEDNO_MESTO = 'app/Services/Ollama/Ollama.php';

    #[Test]
    public function nijedan_drugi_fajl_u_app_ne_gradi_poziv_ka_ollami(): void
    {
        $nadjeno = [];
        $obidjeno = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', RecursiveDirectoryIterator::SKIP_DOTS)) as $fajl) {
            if ($fajl->getExtension() !== 'php') {
                continue;
            }

            $obidjeno++;

            if ($this->gradiPoziv((string) file_get_contents($fajl->getPathname()))) {
                $nadjeno[] = substr($fajl->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            }
        }

        // Dokaz da je merilo obišlo neprazan skup i da vidi jedino mesto poziva.
        $this->assertGreaterThan(5, $obidjeno);
        $this->assertContains(self::JEDNO_MESTO, $nadjeno);
        $this->assertSame([self::JEDNO_MESTO], $nadjeno);
    }

    // Ogledalo: merilo vidi adresu, putanju i podešavanje, a ne vidi komentar o njima.
    #[Test]
    public function merilo_prepoznaje_poziv_a_ne_komentar(): void
    {
        $this->assertTrue($this->gradiPoziv("<?php \$a = 'http://127.0.0.1:11434';"));
        $this->assertTrue($this->gradiPoziv("<?php \$a = '/api/generate';"));
        $this->assertTrue($this->gradiPoziv("<?php \$a = config('ollama.model');"));
        $this->assertFalse($this->gradiPoziv("<?php\n// zove /api/chat na 11434 i config('ollama.model')\n/** ollama.adresa */\n\$a = 1;"));
    }

    private function gradiPoziv(string $kod): bool
    {
        foreach (token_get_all($kod) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('#/api/|11434|\bollama\.#i', $token[1])) {
                return true;
            }
        }

        return false;
    }
}
