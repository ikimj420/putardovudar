<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 67/T18: red „Zvanični izvor" (kalendar, odgovor pomoćnika) nosi klasu običnog teksta, a redovi bez veze (rok, „Sada otvoreno") ostaju kakvi su.
#[Group('baza')]
class RedIzvoraObicanTekstTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    #[Test]
    public function u_kalendaru_red_zvanicni_izvor_je_obican_a_sada_otvoreno_nije(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Dositeja prošle godine', 'otvoreno_do' => '2026-01-26', 'link_izvora' => 'https://fond.rs/dositeja']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Dositeja ove godine', 'link_izvora' => 'https://fond.rs/dositeja/', 'rok' => '2026-12-31']);

        $html = (string) $this->get(route('kalendar.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<p class="red red-izvora">\s*Zvanični izvor:\s*<a href="https://fond\.rs/dositeja"#u', $html));
        // Ogledalo: red bez veze ostaje kako je bio.
        $this->assertSame(1, preg_match('#<p class="red">\s*Sada otvoreno: Dositeja ove godine\s*</p>#u', $html));
        $this->assertSame(1, substr_count($html, 'red-izvora'));
    }

    #[Test]
    public function u_odgovoru_pomocnika_red_zvanicni_izvor_je_obican_a_rok_nije(): void
    {
        Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => 'Našao sam posao radnika u skladištu u Nišu.']]);
        }]);

        $html = (string) $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<p class="red red-izvora">\s*Zvanični izvor:\s*<a href="https://primer\.rs/skladiste-nis"#u', $html));
        $this->assertSame(1, preg_match('#<p class="red">Rok: 15\.11\.2026\.</p>#u', $html));
        $this->assertSame(1, substr_count($html, 'red-izvora'));
    }
}
