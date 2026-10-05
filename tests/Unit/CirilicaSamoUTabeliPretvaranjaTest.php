<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CirilicaSamoUTabeliPretvaranjaTest extends TestCase
{
    #[Test]
    public function ni_jedan_fajl_u_app_osim_latinice_nema_cirilicni_znak(): void
    {
        $obidjeno = 0;
        $saCirilicom = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', RecursiveDirectoryIterator::SKIP_DOTS)) as $fajl) {
            if ($fajl->getExtension() !== 'php') {
                continue;
            }

            $obidjeno++;

            if ($this->imaCirilicu((string) file_get_contents($fajl->getPathname()))) {
                $saCirilicom[] = substr($fajl->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
            }
        }

        // Dokaz da je merilo obišlo neprazan skup i da vidi jedino mesto sa tabelom.
        $this->assertGreaterThan(5, $obidjeno);
        $this->assertContains('app/Support/Latinica.php', $saCirilicom);
        $this->assertSame(['app/Support/Latinica.php'], $saCirilicom);
    }

    // Ogledalo: merilo vidi ćirilicu kad je ima, a ne i u latinici sa dijakriticima.
    #[Test]
    public function merilo_prepoznaje_cirilicu_a_ne_latinicu_sa_dijakriticima(): void
    {
        $this->assertTrue($this->imaCirilicu('<?php $a = "љ";'));
        $this->assertFalse($this->imaCirilicu('<?php $a = "đ š č ć ž lj nj dž";'));
    }

    private function imaCirilicu(string $kod): bool
    {
        return preg_match('/\p{Cyrillic}/u', $kod) === 1;
    }
}
