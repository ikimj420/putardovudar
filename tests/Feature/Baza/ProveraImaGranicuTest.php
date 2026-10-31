<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 96/T1 (PITANJA-85 6): ako bi dnevna provera u jednom prolazu vratila u nacrt više od granice (uvoz.provera_najvise_u_nacrt), izvor je verovatno promenio izgled strana: ne upisuje se ništa, a ispis upozorava.
#[Group('baza')]
class ProveraImaGranicuTest extends BazaTestCase
{
    use RefreshDatabase;

    private const NOV_ROK = 'https://b.test/produzen';

    private const NEMA = 'https://c.test/nema';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function snimak(string $ime): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/stranice/'.$ime));
    }

    /** Prilike čija strana piše da je poziv zatvoren: svaka bi otišla u nacrt. */
    private function zatvorene(int $koliko): void
    {
        foreach (range(1, $koliko) as $broj) {
            Prilika::factory()->objavljena()->create(['naslov' => 'Poziv '.$broj, 'vrsta' => VrstaPrilike::Konkurs, 'rok' => '2026-11-15', 'link_izvora' => 'https://a.test/p'.$broj]);
        }
    }

    /** Jedna prilika kojoj strana piše nov rok i jedna čije strane nema (prvi dan): obe pišu u bazu kad ništa ne zaustavlja prolaz. */
    private function ostale(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Produžen poziv', 'rok' => '2026-11-02', 'link_izvora' => self::NOV_ROK, 'predlog' => ['pravilo' => 5, 'rok' => '2026-11-02', 'citat' => 'Rok je 2. novembra 2026.']]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Poziv bez strane', 'rok' => '2026-11-15', 'link_izvora' => self::NEMA]);
    }

    private function mreza(): void
    {
        Http::fake([
            'https://a.test/*' => Http::response($this->snimak('prosveta-konkurs-istekao.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            self::NOV_ROK => Http::response($this->snimak('prosveta-produzen-rok.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            self::NEMA => Http::response('', 404),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function stanje(): array
    {
        return Prilika::query()->orderBy('id')->get()->map(fn (Prilika $prilika) => $prilika->getAttributes())->all();
    }

    private function pusti(): string
    {
        Artisan::call('prilike:provera');

        return Artisan::output();
    }

    #[Test]
    public function iznad_granice_se_ne_upisuje_nista_ni_nacrt_ni_nov_rok_ni_beleska_i_ispis_upozorava(): void
    {
        $this->zatvorene(11);
        $this->ostale();
        $this->mreza();
        $pre = $this->stanje();

        $izlaz = $this->pusti();

        $this->assertCount(13, $pre);
        $this->assertSame($pre, $this->stanje());
        $this->assertSame(13, Prilika::query()->where('status', StatusPrilike::Objavljeno)->count());
        $this->assertStringContainsString('Upozorenje: u nacrt bi otišlo 11 prilika, a granica je 10; izvor je verovatno promenio izgled strana. Ništa nije upisano.', $izlaz);
        $this->assertStringContainsString('Nije upisano: vraćeno u nacrt (strana piše da je poziv zatvoren): Poziv 1', $izlaz);
        $this->assertStringContainsString('Nije upisano: nov rok 15.11.2026.: Produžen poziv', $izlaz);
        $this->assertStringContainsString('Provereno 12, vraćeno u nacrt 0, nov rok 0.', $izlaz);
        $this->assertStringContainsString('Nije provereno (strana nije stigla ili je prvi put nema): 1.', $izlaz);
    }

    // Izlaz 1: prolaz nije uradio svoj posao, pa to vidi i dnevni posao.
    #[Test]
    public function iznad_granice_izlaz_je_jedan(): void
    {
        $this->zatvorene(11);
        $this->mreza();

        $this->assertSame(1, Artisan::call('prilike:provera'));
    }

    // Ogledalo: tačno na granici piše se sve, i nacrt i nov rok i beleška, bez upozorenja, a izlaz je 0.
    #[Test]
    public function na_granici_se_upisuje_sve_bez_upozorenja(): void
    {
        $this->zatvorene(10);
        $this->ostale();
        $this->mreza();

        $this->assertSame(0, Artisan::call('prilike:provera'));
        $izlaz = Artisan::output();

        $this->assertStringNotContainsString('Upozorenje', $izlaz);
        $this->assertStringNotContainsString('Nije upisano', $izlaz);
        $this->assertStringContainsString('Provereno 11, vraćeno u nacrt 10, nov rok 1.', $izlaz);
        $this->assertSame(10, Prilika::query()->where('status', StatusPrilike::Nacrt)->count());
        $this->assertSame('2026-11-15', Prilika::query()->where('naslov', 'Produžen poziv')->firstOrFail()->rok?->toDateString());
        $this->assertSame('2026-10-31', data_get(Prilika::query()->where('naslov', 'Poziv bez strane')->firstOrFail()->predlog, 'nedostupna_od'));
    }

    // Granica je ona iz podešavanja, ne broj upisan u komandi.
    #[Test]
    public function granica_je_ona_iz_podesavanja(): void
    {
        $this->assertSame(10, config('uvoz.provera_najvise_u_nacrt'));
        $this->zatvorene(3);
        $this->mreza();

        config()->set('uvoz.provera_najvise_u_nacrt', 3);
        $this->assertSame(0, Artisan::call('prilike:provera'));
        $this->assertSame(3, Prilika::query()->where('status', StatusPrilike::Nacrt)->count());

        Prilika::query()->update(['status' => StatusPrilike::Objavljeno]);
        config()->set('uvoz.provera_najvise_u_nacrt', 2);
        $this->assertSame(1, Artisan::call('prilike:provera'));
        $this->assertSame(0, Prilika::query()->where('status', StatusPrilike::Nacrt)->count());
    }

    // Granica se tiče samo vraćanja u nacrt: mnogo novih rokova odjednom nije znak da je izgled strana promenjen, pa se upisuju.
    #[Test]
    public function mnogo_novih_rokova_ne_zaustavlja_prolaz(): void
    {
        foreach (range(1, 12) as $broj) {
            Prilika::factory()->objavljena()->create(['naslov' => 'Produžen '.$broj, 'rok' => '2026-11-02', 'link_izvora' => self::NOV_ROK.'/'.$broj, 'predlog' => ['pravilo' => 5, 'rok' => '2026-11-02', 'citat' => 'Rok je 2. novembra 2026.']]);
        }
        Http::fake([self::NOV_ROK.'/*' => Http::response($this->snimak('prosveta-produzen-rok.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $this->assertSame(0, Artisan::call('prilike:provera'));

        $this->assertStringContainsString('Provereno 12, vraćeno u nacrt 0, nov rok 12.', Artisan::output());
        $this->assertSame(12, Prilika::query()->where('rok', '2026-11-15')->count());
    }

    // Posle zaustavljenog prolaza ništa nije zapamćeno: kad izvor ponovo daje pravo stanje, sledeći prolaz radi kao da zaustavljanja nije bilo.
    #[Test]
    public function sledeci_prolaz_posle_zaustavljenog_radi_normalno(): void
    {
        $this->zatvorene(11);
        $this->mreza();
        $this->assertSame(1, Artisan::call('prilike:provera'));
        $this->assertSame(0, Prilika::query()->where('status', StatusPrilike::Nacrt)->count());

        // Izvor je popravljen: strane su otvorene, samo dve još pišu da su zatvorene.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://a.test/p1' => Http::response($this->snimak('prosveta-konkurs-istekao.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://a.test/p2' => Http::response($this->snimak('prosveta-konkurs-istekao.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://a.test/*' => Http::response('<html><body><article><p>Rok za prijavu je 15. novembra 2026. godine.</p></article></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $this->assertSame(0, Artisan::call('prilike:provera'));
        $this->assertStringContainsString('Provereno 11, vraćeno u nacrt 2, nov rok 0.', Artisan::output());
        $this->assertSame(2, Prilika::query()->where('status', StatusPrilike::Nacrt)->count());
    }

    // Dnevni posao ne staje zbog toga: ostali koraci idu, a izlaz mu je 1.
    #[Test]
    public function dnevni_posao_nastavlja_posle_zaustavljene_provere_a_izlaz_je_jedan(): void
    {
        config()->set('uvoz.izvori', []);
        config()->set('uvoz.spiskovi', []);
        $this->zatvorene(11);
        $this->mreza();

        $this->artisan('uvoz:dnevno')
            ->expectsOutputToContain('Upozorenje: u nacrt bi otišlo 11 prilika')
            ->expectsOutputToContain('→ prilike:arhiviraj-stare')
            ->expectsOutputToContain('→ kalendar:iz-prilika')
            ->assertExitCode(1);

        $this->assertSame(0, Prilika::query()->where('status', StatusPrilike::Nacrt)->count());
    }
}
