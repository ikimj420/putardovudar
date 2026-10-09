<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class UvozDnevnoTest extends BazaTestCase
{
    use RefreshDatabase;

    private const FEED = 'https://izvor-a.test/feed/';

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        // Nijedan zahtev ne ide na pravi internet ni na pravu Ollamu: što nije lažirano, baca izuzetak.
        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => self::FEED]]);
    }

    private function internet(int $statusFeeda = 200): void
    {
        Http::fake(function (Request $zahtev) use ($statusFeeda) {
            return match (true) {
                $zahtev->url() === self::FEED => Http::response((string) file_get_contents(base_path('tests/Fixtures/rss/youth-rs.xml')), $statusFeeda),
                $zahtev->url() === self::OLLAMA => Http::response(['message' => ['content' => json_encode(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.', 'vrsta' => null, 'rok' => null, 'citat' => '', 'osnov' => null, 'citat_pravila' => ''])]]),
                default => Http::response('<html><body><p>Strana izvora.</p></body></html>'),
            };
        });
    }

    #[Test]
    public function dnevni_posao_prvo_uvozi_pa_obradjuje_ono_sto_je_upravo_uvezeno(): void
    {
        $this->internet();

        $this->artisan('uvoz:dnevno')
            ->expectsOutputToContain('→ uvoz:rss')
            ->expectsOutputToContain('→ uvoz:obradi')
            ->assertExitCode(0);

        $this->assertSame(3, Prilika::query()->count());
        $this->assertSame(0, Prilika::query()->whereNull('obradeno_at')->count());
    }

    // Ogledalo: kad izvor ne radi, obrada i dalje ide nad onim što već postoji, a posao to prijavljuje izlazom 1.
    #[Test]
    public function izvor_koji_ne_radi_ne_zaustavlja_obradu_ali_se_vidi_u_izlazu(): void
    {
        $this->internet(statusFeeda: 503);
        $postojeci = Prilika::factory()->create(['naslov' => 'Već uvezen', 'naziv_izvora' => 'Izvor A', 'vrsta' => 'drugo', 'rok' => null, 'link_izvora' => 'https://izvor-a.test/vec-uvezen']);

        $this->artisan('uvoz:dnevno')->assertExitCode(1);

        $this->assertNotNull($postojeci->fresh()->obradeno_at);
        $this->assertSame(1, Prilika::query()->count());
    }

    #[Test]
    public function komande_idu_tim_redosledom(): void
    {
        $this->internet();

        $this->artisan('uvoz:dnevno')->run();

        $adrese = Http::recorded()->map(fn (array $par) => $par[0]->url())->values()->all();
        $prvaOllama = array_search(self::OLLAMA, $adrese, true);
        $feed = array_search(self::FEED, $adrese, true);

        $this->assertNotFalse($feed);
        $this->assertNotFalse($prvaOllama);
        $this->assertLessThan($prvaOllama, $feed);
    }

    // Paket 17: Jooble ide između RSS-a i obrade, a bez ključa se preskače bez zahteva.
    #[Test]
    public function jooble_ide_posle_rss_a_a_pre_obrade_kad_ima_kljuca(): void
    {
        config()->set('jooble.kljuc', 'PROBNI-KLJUC-123');
        Http::fake(function (Request $zahtev) {
            return match (true) {
                $zahtev->url() === self::FEED => Http::response((string) file_get_contents(base_path('tests/Fixtures/rss/youth-rs.xml'))),
                $zahtev->url() === 'https://jooble.org/api/PROBNI-KLJUC-123' => Http::response(['jobs' => [['title' => 'Posao sa Jooble-a', 'snippet' => 'Opis', 'link' => 'https://rs.jooble.org/desc/9']]]),
                $zahtev->url() === self::OLLAMA => Http::response(['message' => ['content' => json_encode(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.', 'vrsta' => null, 'rok' => null, 'citat' => '', 'osnov' => null, 'citat_pravila' => ''])]]),
                default => Http::response('<html><body><p>Strana izvora.</p></body></html>'),
            };
        });

        $this->artisan('uvoz:dnevno')->expectsOutputToContain('→ uvoz:jooble')->assertExitCode(0);

        $adrese = Http::recorded()->map(fn (array $par) => $par[0]->url())->values()->all();

        $this->assertLessThan((int) array_search('https://jooble.org/api/PROBNI-KLJUC-123', $adrese, true), (int) array_search(self::FEED, $adrese, true));
        $this->assertLessThan((int) array_search(self::OLLAMA, $adrese, true), (int) array_search('https://jooble.org/api/PROBNI-KLJUC-123', $adrese, true));
        $this->assertSame(4, Prilika::query()->count());
        $this->assertSame(0, Prilika::query()->whereNull('obradeno_at')->count());
    }

    #[Test]
    public function bez_kljuca_dnevni_posao_ne_zove_jooble_i_ne_pada(): void
    {
        $this->internet();

        $this->artisan('uvoz:dnevno')->expectsOutput('Jooble: preskočeno, JOOBLE_KLJUC nije podešen.')->assertExitCode(0);

        Http::assertNotSent(fn (Request $zahtev) => str_contains($zahtev->url(), 'jooble'));
    }
}
