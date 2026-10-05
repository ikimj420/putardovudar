<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PravilaJavnogJeNaJednomMestuTest extends TestCase
{
    private const SMEJU = ['Models/Prilika.php', 'Enums/StatusPrilike.php'];

    #[Test]
    public function samo_model_i_status_znaju_za_objavljeno(): void
    {
        $nadjeno = [];
        $obidjeno = 0;

        foreach (['app', 'routes'] as $folder) {
            foreach ($this->phpFajlovi(dirname(__DIR__, 2).'/'.$folder) as $fajl) {
                $obidjeno++;

                if ($this->pominjeObjavljeno(file_get_contents($fajl))) {
                    $nadjeno[] = substr($fajl, strlen(dirname(__DIR__, 2).'/'));
                }
            }
        }

        $this->assertGreaterThan(0, $obidjeno);
        // Dokaz da merilo vidi: oba dozvoljena mesta moraju da se nađu.
        $this->assertContains('app/Models/Prilika.php', $nadjeno);
        $this->assertContains('app/Enums/StatusPrilike.php', $nadjeno);

        $tudja = array_values(array_filter($nadjeno, fn (string $fajl) => ! in_array(substr($fajl, 4), self::SMEJU, true)));

        $this->assertSame([], $tudja);
    }

    // Ogledalo: merilo vidi i enum i tekst, a ne vidi komentar.
    #[Test]
    public function merilo_prepoznaje_objavljeno_i_ne_broji_komentare(): void
    {
        $this->assertTrue($this->pominjeObjavljeno('<?php $a = StatusPrilike::Objavljeno;'));
        $this->assertTrue($this->pominjeObjavljeno("<?php \$a = 'objavljeno';"));
        $this->assertFalse($this->pominjeObjavljeno("<?php\n// Objavljeno i 'objavljeno' samo u modelu\n"));
    }

    private function pominjeObjavljeno(string $kod): bool
    {
        foreach (token_get_all($kod) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === 'Objavljeno') {
                return true;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '\'"') === 'objavljeno') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function phpFajlovi(string $folder): array
    {
        $fajlovi = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $fajl) {
            if ($fajl->getExtension() === 'php') {
                $fajlovi[] = $fajl->getPathname();
            }
        }

        return $fajlovi;
    }
}
