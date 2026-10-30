<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 86/T3: izmereno postojeće ponašanje za status „arhivirano": ne vraća se u obradu (ni obična ni „--ponovo"), ne pravi se iz uvoza ponovo, ne ulazi u kalendar i nema ga na sajtu.
// Pravilo objave nije menjano; svaka tvrdnja ima ogledalo sa istim zapisom u drugom statusu.
#[Group('baza')]
class ArhiviranaPrilikaNeIdeUObraduNitiNaSajtTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const STRANA = 'https://a.test/oglas-1';

    private const FEED = 'https://a.test/feed';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 30, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => self::FEED]]);
        config()->set('uvoz.spiskovi', []);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(StatusPrilike $status, array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...[
            'naslov' => 'Poziv za obuku', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'status' => $status, 'naziv_izvora' => 'Izvor A', 'link_izvora' => self::STRANA,
        ], ...$dopuna]);
    }

    private function pozivaOllame(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA)->count();
    }

    private function lazniIzvorIModel(): void
    {
        Http::fake([
            self::STRANA => Http::response('<html><body><p>Strana poziva.</p></body></html>'),
            self::OLLAMA => Http::response(['message' => ['content' => json_encode(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.', 'grupe' => [], 'vrsta' => null, 'rok' => null, 'citat' => ''])]]),
        ]);
    }

    #[Test]
    public function obrada_ne_uzima_arhiviranu_priliku_a_uzima_nacrt_istog_izgleda(): void
    {
        $arhivirana = $this->prilika(StatusPrilike::Arhivirano);
        $this->lazniIzvorIModel();

        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.')->assertExitCode(0);

        $this->assertSame(0, $this->pozivaOllame());
        $this->assertNull($arhivirana->fresh()->obradeno_at);

        // Ogledalo: isti zapis kao nacrt ide u obradu.
        $arhivirana->forceFill(['status' => StatusPrilike::Nacrt])->save();

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        $this->assertSame(1, $this->pozivaOllame());
        $this->assertNotNull($arhivirana->fresh()->obradeno_at);
    }

    // „--ponovo" uzima obrađene po starom pravilu, ali samo nacrte.
    #[Test]
    public function ponovna_obrada_ne_uzima_arhiviranu_priliku_a_uzima_nacrt_istog_izgleda(): void
    {
        $zapis = ['obradeno_at' => now()->subDay(), 'predlog' => ['pravilo' => 1, 'odbijeno' => 'pravilo za objavu ne važi']];
        $arhivirana = $this->prilika(StatusPrilike::Arhivirano, $zapis);
        $this->lazniIzvorIModel();

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutput('Nema nacrta iz uvoza za obradu.')->assertExitCode(0);

        $this->assertSame(0, $this->pozivaOllame());

        $arhivirana->forceFill(['status' => StatusPrilike::Nacrt])->save();
        $arhivirana->forceFill(['updated_at' => $arhivirana->obradeno_at])->saveQuietly();

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->assertExitCode(0);

        $this->assertSame(1, $this->pozivaOllame());
    }

    #[Test]
    public function sajt_ne_pokazuje_arhiviranu_priliku_a_pokazuje_objavljenu_istog_izgleda(): void
    {
        $arhivirana = $this->prilika(StatusPrilike::Arhivirano, ['naslov' => 'Arhivirana prilika za obuku', 'rok' => '2026-12-01']);
        $objavljena = $this->prilika(StatusPrilike::Objavljeno, ['naslov' => 'Objavljena prilika za obuku', 'rok' => '2026-12-01', 'link_izvora' => 'https://a.test/oglas-2']);

        $this->assertFalse(Prilika::javne()->whereKey($arhivirana->getKey())->exists());
        $this->assertTrue(Prilika::javne()->whereKey($objavljena->getKey())->exists());

        $this->get(route('prilike.show', $arhivirana->slug))->assertNotFound();
        $this->get(route('prilike.show', $objavljena->slug))->assertOk()->assertSee('Objavljena prilika za obuku');

        foreach ([route('prilike.index'), route('pocetna')] as $adresa) {
            $this->get($adresa)->assertOk()->assertDontSee('Arhivirana prilika za obuku')->assertSee('Objavljena prilika za obuku');
        }
    }

    // Uvoz poznaje zapis po vezi izvora u svakom statusu: arhivirana prilika se ne pravi ponovo kao nov nacrt.
    #[Test]
    public function uvoz_ne_pravi_ponovo_arhiviranu_priliku_a_pravi_novu(): void
    {
        $arhivirana = $this->prilika(StatusPrilike::Arhivirano, ['naslov' => 'Stari poziv']);
        $feed = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Izvor</title>'
            .'<item><title>Stari poziv</title><link>'.self::STRANA.'</link><description>Opis.</description></item>'
            .'<item><title>Nov poziv</title><link>https://a.test/oglas-novi</link><description>Opis.</description></item>'
            .'</channel></rss>';
        Http::fake([self::FEED => Http::response($feed)]);

        $this->artisan('uvoz:rss')->assertExitCode(0);

        $this->assertSame(1, Prilika::query()->where('link_izvora', self::STRANA)->count());
        $this->assertSame(StatusPrilike::Arhivirano, $arhivirana->fresh()->status);
        $this->assertSame(StatusPrilike::Nacrt, Prilika::query()->where('link_izvora', 'https://a.test/oglas-novi')->firstOrFail()->status);
    }

    #[Test]
    public function kalendar_ne_uzima_arhiviranu_priliku_a_uzima_objavljenu_istog_izgleda(): void
    {
        $this->prilika(StatusPrilike::Arhivirano, ['naslov' => 'Arhivirana sa prošlim rokom', 'rok' => '2026-09-01']);
        $this->artisan('kalendar:iz-prilika')->expectsOutput('Novih nacrta u kalendaru: 0.')->assertExitCode(0);

        $this->prilika(StatusPrilike::Objavljeno, ['naslov' => 'Objavljena sa prošlim rokom', 'rok' => '2026-09-01', 'link_izvora' => 'https://a.test/oglas-2']);
        $this->artisan('kalendar:iz-prilika')->expectsOutput('Novih nacrta u kalendaru: 1.')->assertExitCode(0);
    }
}
