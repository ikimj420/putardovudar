<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\VidljivTekst;
use Tests\TestCase;

// Ne dira bazu, pa prolazi i kad server baze ne radi.
class NepostojecaStranaVraca404Test extends TestCase
{
    use VidljivTekst;

    #[Test]
    public function nepostojeca_strana_vraca_404(): void
    {
        $this->get('/ovde-nema-nicega')->assertStatus(404);
    }

    #[Test]
    public function nepostojeca_strana_kaze_na_srpskom_da_ne_postoji_a_ne_na_engleskom(): void
    {
        $odgovor = $this->get('/ovde-nema-nicega')->assertStatus(404);

        $odgovor->assertSee('Ta strana ne postoji ili prilika više nije otvorena.');
        $odgovor->assertDontSee('Not Found');
        $this->assertSame('404 - Putardo Vudar', $this->naslovStrane($odgovor->getContent()));
    }

    // Ogledalo: strana ima isto zaglavlje kao ostale javne strane, pa se sa nje može nazad na prilike.
    #[Test]
    public function nepostojeca_strana_ima_zaglavlje_sa_linkom_prilike(): void
    {
        $this->get('/ovde-nema-nicega')->assertStatus(404)->assertSee('<a href="'.route('prilike.index').'">Prilike</a>', false);
    }
}
