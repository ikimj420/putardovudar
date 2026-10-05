<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IzgledJavnihStranaTest extends TestCase
{
    #[Test]
    public function svaka_klasa_iz_javnih_pogleda_ima_stil_u_javno_css(): void
    {
        $korenski = dirname(__DIR__, 2);
        $stil = $this->klaseIzStila((string) file_get_contents($korenski.'/public/css/javno.css'));
        $koriscene = [];

        foreach (glob($korenski.'/resources/views/javno/*.blade.php') ?: [] as $pogled) {
            $koriscene += array_fill_keys($this->klaseIzPogleda((string) file_get_contents($pogled)), $pogled);
        }

        // Dokaz da je merilo obišlo neprazan skup: pogledi koriste klase, a stil ih nabraja.
        $this->assertNotSame([], $koriscene);
        $this->assertNotSame([], $stil);
        $this->assertSame([], array_values(array_diff(array_keys($koriscene), $stil)), 'Klase bez stila u javno.css');
    }

    // Ogledalo: merilo vidi klasu bez stila i ne broji klasu iz komentara.
    #[Test]
    public function merilo_prepoznaje_klase_u_pogledu_i_u_stilu(): void
    {
        $this->assertSame(['kartica', 'red'], $this->klaseIzPogleda('<article class="kartica"><p class="red">x</p></article>'));
        $this->assertSame(['kartica', 'red'], $this->klaseIzStila('.kartica h2 { } /* .komentar */ .red:hover { }'));
    }

    #[Test]
    public function stil_je_obican_css_bez_build_koraka(): void
    {
        $stil = (string) file_get_contents(dirname(__DIR__, 2).'/public/css/javno.css');
        $raspored = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/javno/raspored.blade.php');

        $this->assertNotSame('', $stil);
        $this->assertDoesNotMatchRegularExpression('/@(tailwind|apply|import|theme|source)\b/', $stil);
        $this->assertStringNotContainsString('@vite', $raspored);
    }

    /** @return list<string> */
    private function klaseIzPogleda(string $pogled): array
    {
        preg_match_all('/\bclass="([^"{]+)"/', $pogled, $nalazi);

        return array_values(array_unique(preg_split('/\s+/', trim(implode(' ', $nalazi[1]))) ?: []));
    }

    /** @return list<string> */
    private function klaseIzStila(string $stil): array
    {
        $stil = preg_replace('#/\*.*?\*/#s', '', $stil) ?? '';
        preg_match_all('/\.([a-zA-Z][\w-]*)/', $stil, $nalazi);

        return array_values(array_unique($nalazi[1]));
    }
}
