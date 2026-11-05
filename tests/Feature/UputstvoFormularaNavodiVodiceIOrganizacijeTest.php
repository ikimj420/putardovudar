<?php

namespace Tests\Feature;

use App\Services\Pomocnik\PitanjeUFormular;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Podrska\LazniClaude;
use Tests\TestCase;

// Paket 101/T5: pomoćnik od paketa 23 i 24 traži i vodiče i organizacije, a uputstvo za formular je govorilo samo o prilikama.
// Koliko to menja ono što pravi Claude vrati meri proba (PROBA-PAKET-101.md); ovde se meri da uputstvo to kaže i da formular ostaje formular.
class UputstvoFormularaNavodiVodiceIOrganizacijeTest extends TestCase
{
    #[Test]
    public function uputstvo_za_formular_navodi_prilike_vodice_i_organizacije(): void
    {
        Http::preventStrayRequests();
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor('{"vrsta":null,"grad":null,"kome":null,"kljucne_reci":["CV"]}')]);

        $formular = app(PitanjeUFormular::class)->izPitanja('pomozi mi oko CV-a');

        $this->assertSame(['CV'], $formular->kljucneReci);
        $pozivi = Http::recorded(fn (Request $zahtev) => $zahtev->url() === LazniClaude::ADRESA);
        $this->assertCount(1, $pozivi);
        $uputstvo = LazniClaude::uputstvo($pozivi[0][0]);

        $this->assertStringContainsString('Pretvaraš pitanje o prilikama (posao, praksa, stipendija, konkurs, obuka), vodičima ili organizacijama u formular za pretragu.', $uputstvo);
        $this->assertStringNotContainsString('obuka) u formular', $uputstvo);
        // Ogledalo: model i dalje samo popunjava formular, ne odgovara na pitanje i vraća JSON.
        $this->assertStringContainsString('Ne odgovaraš na pitanje.', $uputstvo);
        $this->assertTrue(LazniClaude::jeJson($pozivi[0][0]));
        $this->assertSame('pomozi mi oko CV-a', LazniClaude::poruka($pozivi[0][0]));
    }
}
