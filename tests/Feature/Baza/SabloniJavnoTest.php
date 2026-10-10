<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 56 T2 (Ivanova odluka 10.10.2026.): šabloni postoje samo u adminu; posetilac ih dobija kroz vodič i pomoćnika, ne kao zasebne strane.
#[Group('baza')]
class SabloniJavnoTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    #[Test]
    public function stare_adrese_vode_na_vodice_trajno(): void
    {
        $objavljen = Sablon::factory()->objavljen()->create(['naslov' => 'Molba']);

        foreach (['/sabloni', '/sabloni/'.$objavljen->slug, '/sabloni/nema-takvog'] as $adresa) {
            $this->get($adresa)->assertStatus(301)->assertRedirect(route('vodici.index'));
        }

        // Ogledalo: adresa na koju vode radi i nije preusmerena.
        $this->get(route('vodici.index'))->assertOk();
    }

    // Stranica šablona i sklapanje nisu više putanje sajta: nema imenovanih ruta koje ih opisuju.
    #[Test]
    public function javne_strane_sablona_ne_postoje_kao_rute(): void
    {
        $this->assertFalse(Route::has('sabloni.index'));
        $this->assertFalse(Route::has('sabloni.show'));
        $this->assertFalse(Route::has('sabloni.sklopi'));
        $this->assertTrue(Route::has('vodici.index'), 'merilo mora da vidi bar jednu rutu');
    }

    #[Test]
    public function meni_nema_sabloni_a_ima_ostale_stavke(): void
    {
        Sablon::factory()->objavljen()->create(['naslov' => 'Molba']);

        foreach ([route('pocetna'), route('vodici.index'), route('prilike.index')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();
            preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);

            $this->assertStringNotContainsString('Šabloni', $zaglavlje[1] ?? '', $adresa);
            $this->assertStringNotContainsString('/sabloni', $zaglavlje[1] ?? '', $adresa);
            $this->assertStringContainsString('>Vodiči<', $zaglavlje[1] ?? '', $adresa);
        }
    }

    // Objavljen šablon se ne pojavljuje ni na jednoj javnoj strani po sebi; vodič ga nosi tek preko izbora (ogledalo ide u testu „Napiši moj").
    #[Test]
    public function objavljen_sablon_se_ne_pojavljuje_na_javnim_stranama(): void
    {
        Sablon::factory()->objavljen()->create(['naslov' => 'Tajni naslov šablona', 'objasnjenje' => 'Tajno objašnjenje šablona.']);
        $vodic = Vodic::factory()->objavljen()->create();

        foreach ([route('pocetna'), route('vodici.index'), route('vodici.show', $vodic->slug), route('pomocnik.index'), route('prilike.index')] as $adresa) {
            $tekst = $this->vidljivTekst($this->get($adresa)->assertOk()->getContent());

            $this->assertStringNotContainsString('Tajni naslov šablona', $tekst, $adresa);
            $this->assertStringNotContainsString('Tajno objašnjenje šablona.', $tekst, $adresa);
        }
    }
}
