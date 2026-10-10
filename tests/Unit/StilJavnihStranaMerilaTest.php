<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\StilJavnihStrana;

// Merilo koje čita stil mora samo da dokaže da vidi ono što treba: komentar nestaje, @media nestaje, pravilo van njega ostaje.
class StilJavnihStranaMerilaTest extends TestCase
{
    use StilJavnihStrana;

    #[Test]
    public function komentar_se_uklanja_a_pravilo_pored_njega_ostaje(): void
    {
        $stil = self::uklanjaKomentare(".a { gap: 1px; }\n/* .b { gap: 2px; } */\n.c { gap: 3px; }");

        $this->assertStringContainsString('.a { gap: 1px; }', $stil);
        $this->assertStringContainsString('.c { gap: 3px; }', $stil);
        $this->assertStringNotContainsString('.b', $stil);
    }

    #[Test]
    public function media_blok_se_uklanja_ceo_a_pravila_pre_i_posle_njega_ostaju(): void
    {
        $stil = self::uklanjaUpite(".a { gap: 1px; }\n@media (max-width: 10px) {\n  .b { gap: 2px; }\n  .c { gap: 4px; }\n}\n.d { gap: 3px; }");

        $this->assertStringContainsString('.a { gap: 1px; }', $stil);
        $this->assertStringContainsString('.d { gap: 3px; }', $stil);
        $this->assertStringNotContainsString('.b', $stil);
        $this->assertStringNotContainsString('.c', $stil);
        $this->assertStringNotContainsString('@media', $stil);
    }

    // Dokaz da je merilo obišlo neprazan skup: pravi stil ima i komentare i @media blokove, a posle uklanjanja ostaje sadržaj.
    #[Test]
    public function pravi_stil_ima_komentare_i_upite_koje_merilo_uklanja(): void
    {
        $sirov = (string) file_get_contents(dirname(__DIR__, 2).'/public/css/javno.css');

        $this->assertStringContainsString('/*', $sirov);
        $this->assertStringContainsString('@media', $sirov);
        $this->assertStringNotContainsString('/*', $this->stilBezKomentara());
        $this->assertStringNotContainsString('@media', $this->stilBezUpita());
        $this->assertStringContainsString('.meta-stavka {', $this->stilBezUpita());
    }
}
