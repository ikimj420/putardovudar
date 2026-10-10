<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Pomocnik\Pomocnik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 23, T3: opasno pitanje dobija jednu rečenicu, bez modela i bez pretrage.
#[Group('baza')]
class PomocnikOpasnaPitanjaTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
        Http::preventStrayRequests();
        // Zapisi koje bi pretraga našla da se pita: opasno pitanje ih ne sme dobiti ni kad postoje.
        Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
        Vodic::factory()->objavljen()->create(['naslov' => 'Šta ako ti fali dokument?', 'kratak_opis' => 'Kako da dođeš do kopije.']);

        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => ['dokument']])]])
                : Http::response(['message' => ['content' => 'U Nišu postoji posao radnika u skladištu.']]);
        }]);
    }

    #[Test]
    public function opasno_pitanje_dobija_jednu_recenicu_bez_modela_pretrage_i_izvora(): void
    {
        $odgovor = app(Pomocnik::class)->odgovori('Kako da lažiram dokument?');

        $this->assertSame(Pomocnik::NE_MOGU_DA_POMOGNEM, $odgovor->tekst);
        $this->assertSame('U tome ne mogu da pomognem.', $odgovor->tekst);
        $this->assertSame([], $odgovor->izvori);
        $this->assertTrue($odgovor->odbijeno);
        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertFalse($odgovor->jeIzSablona());
        Http::assertNothingSent();
    }

    #[Test]
    public function opasno_pitanje_na_cirilici_i_bez_dijakritika_se_isto_odbija(): void
    {
        foreach (['Како да лажирам документ?', 'kako da laziram dokument', 'KAKO DA FALSIFIKUJEM POTPIS'] as $pitanje) {
            $odgovor = app(Pomocnik::class)->odgovori($pitanje);

            $this->assertTrue($odgovor->odbijeno, $pitanje);
        }

        Http::assertNothingSent();
    }

    // Ogledalo: isto pitanje bez opasne reči ide do modela, a odgovor ima zapise i izvore.
    #[Test]
    public function obicno_pitanje_istih_zapisa_ide_do_modela_i_nije_odbijeno(): void
    {
        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertFalse($odgovor->odbijeno);
        $this->assertNotSame(Pomocnik::NE_MOGU_DA_POMOGNEM, $odgovor->tekst);
        $this->assertNotEmpty($odgovor->izvori);
        Http::assertSentCount(2);
    }

    #[Test]
    public function pretraga_opasnog_pitanja_je_prazna_i_ne_zove_model(): void
    {
        $rezultat = app(Pomocnik::class)->pretrazi('Kako da lažiram dokument?');

        $this->assertTrue($rezultat->nemaPodatak());
        $this->assertTrue($rezultat->formular->jePrazan());
        Http::assertNothingSent();
    }

    // Opasan deo mora da stane u prvih 300 znakova, jer samo toliko ide modelu.
    #[Test]
    public function opasna_rec_posle_tristo_znakova_ne_stize_do_modela_pa_je_ne_treba_odbijati(): void
    {
        $pitanje = str_repeat('Ima li posla u Nišu? ', 15).'lažiram dokument';

        $this->assertGreaterThan(Pomocnik::NAJVISE_ZNAKOVA_PITANJA, mb_strlen($pitanje));
        $this->assertFalse(app(Pomocnik::class)->odgovori($pitanje)->odbijeno);
    }

    #[Test]
    public function strana_pokazuje_recenicu_bez_kartica_izvora_i_ne_zove_model(): void
    {
        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Kako da lažiram dokument?'])->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('U tome ne mogu da pomognem.', $tekst);
        $this->assertStringNotContainsString('Izvori', $tekst);
        $this->assertStringNotContainsString('Šta ako ti fali dokument?', $tekst);
        $this->assertSame(0, preg_match('#<article class="kartica">#', $html));
        Http::assertNothingSent();
    }
}
