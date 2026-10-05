<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\VidljivTekst;

class VidljivTekstTest extends TestCase
{
    use VidljivTekst;

    #[Test]
    public function vidljiv_je_samo_tekst_a_ne_atributi_skripte_i_naslov(): void
    {
        $html = '<html><head><title>Naslov - Sajt</title><script>var Create = 1;</script></head>'
            .'<body><div wire:snapshot="{&quot;name&quot;:&quot;CreateAction&quot;}"><p>Napravi <b>priliku</b></p></div></body></html>';

        $this->assertSame('Napravi priliku', $this->vidljivTekst($html));
        $this->assertSame('Naslov - Sajt', $this->naslovStrane($html));
    }

    // Ogledalo: tekst ćirilicom i dalje ostaje vidljiv, pa ga merilo za ćirilicu može naći.
    #[Test]
    public function cirilica_u_tekstu_ostaje_vidljiva(): void
    {
        $this->assertSame(1, preg_match('/\p{Cyrillic}/u', $this->vidljivTekst('<p>Нова прилика</p>')));
    }
}
