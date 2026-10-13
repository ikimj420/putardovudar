<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// Paket 71/T1: samo klasa Claude zna za Claude API (adresu, putanju, podešavanja), kao što samo Ollama zna za Ollamu.
class ClaudeSeZoveSamoIzJedneKlaseTest extends TestCase
{
    private const JEDNO_MESTO = 'app/Services/Claude/Claude.php';

    #[Test]
    public function nijedan_drugi_fajl_u_app_ne_gradi_poziv_ka_claude_api_ju(): void
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

    // Ogledalo: merilo vidi adresu, putanju i ključ iz okruženja, a ne vidi komentar o njima.
    #[Test]
    public function merilo_prepoznaje_poziv_a_ne_komentar(): void
    {
        $this->assertTrue($this->gradiPoziv("<?php \$a = 'https://api.anthropic.com';"));
        $this->assertTrue($this->gradiPoziv("<?php \$a = '/v1/messages';"));
        $this->assertTrue($this->gradiPoziv("<?php \$a = 'x-api-key';"));
        $this->assertTrue($this->gradiPoziv("<?php \$a = env('ANTHROPIC_API_KEY');"));
        $this->assertFalse($this->gradiPoziv("<?php\n// zove api.anthropic.com, /v1/messages i ANTHROPIC_API_KEY\n/** x-api-key */\n\$a = 1;"));
    }

    private function gradiPoziv(string $kod): bool
    {
        foreach (token_get_all($kod) as $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('#anthropic|/v1/messages|x-api-key|ANTHROPIC_API_KEY#i', $token[1])) {
                return true;
            }
        }

        return false;
    }
}
