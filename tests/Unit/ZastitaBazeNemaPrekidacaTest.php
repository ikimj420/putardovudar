<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ZastitaBazeNemaPrekidacaTest extends TestCase
{
    private const FAJLOVI = ['ZastitaBaze.php', 'TestCase.php'];

    #[Test]
    public function zastita_ne_cita_okruzenje_i_ne_preskace_testove(): void
    {
        $obidjeno = 0;

        foreach (self::FAJLOVI as $fajl) {
            $kod = file_get_contents(dirname(__DIR__).'/'.$fajl);
            $obidjeno++;

            $this->assertSame([], $this->prekidaci($kod), $fajl.' sadrži način da se zaštita isključi');
        }

        $this->assertSame(2, $obidjeno);
    }

    // Ogledalo: merilo mora da vidi prekidač kad ga ima, a da ne vidi komentar koji o njemu govori.
    #[Test]
    public function merilo_prepoznaje_prekidac_a_ne_komentar(): void
    {
        $this->assertNotSame([], $this->prekidaci('<?php if (getenv("SKIP")) { return; }'));
        $this->assertNotSame([], $this->prekidaci('<?php $a = $_ENV["SKIP"];'));
        $this->assertNotSame([], $this->prekidaci('<?php $this->markTestSkipped("x");'));
        $this->assertSame([], $this->prekidaci("<?php\n// getenv, env i markTestSkipped nisu dozvoljeni\n"));
    }

    /** @return list<string> */
    private function prekidaci(string $kod): array
    {
        $nalazi = [];

        foreach (token_get_all($kod) as $token) {
            if (! is_array($token)) {
                continue;
            }

            $jePoziv = $token[0] === T_STRING && in_array($token[1], ['getenv', 'putenv', 'env', 'markTestSkipped', 'markTestIncomplete'], true);
            $jeNiz = $token[0] === T_VARIABLE && in_array($token[1], ['$_ENV', '$_SERVER', '$_GET', '$_POST', '$_COOKIE'], true);

            if ($jePoziv || $jeNiz) {
                $nalazi[] = $token[1];
            }
        }

        return $nalazi;
    }
}
