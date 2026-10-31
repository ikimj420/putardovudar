<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 86/T2b (Ivan, 30.10.2026.: „zašto ja, zašto ne AI"): dnevni posao arhivira nacrte kojima je rok prošao pre više od 30 dana. Objavljene ne dira, i ništa ne briše.
#[Group('baza')]
class ArhiviranjeStarihNacrtaTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 30, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function arhiviraj(): string
    {
        $this->assertSame(0, Artisan::call('prilike:arhiviraj-stare'));

        return Artisan::output();
    }

    #[Test]
    public function nacrt_kome_je_rok_prosao_pre_vise_od_trideset_dana_se_arhivira(): void
    {
        $star = Prilika::factory()->create(['rok' => '2026-09-29']);

        $this->assertStringContainsString('Arhivirano starih nacrta: 1.', $this->arhiviraj());

        $this->assertSame(StatusPrilike::Arhivirano, $star->fresh()->status);
        $this->assertSame(1, Prilika::query()->count());
    }

    // Ogledalo: tačno trideset dana, noviji rok, nacrt bez roka i stalno otvoren nacrt ostaju nacrti; objavljena i već arhivirana se ne dotiču.
    #[Test]
    public function ostalo_ostaje_kako_je(): void
    {
        $tacnoTrideset = Prilika::factory()->create(['rok' => '2026-09-30']);
        $noviji = Prilika::factory()->create(['rok' => '2026-10-15']);
        $bezRoka = Prilika::factory()->create(['rok' => null]);
        $stalno = Prilika::factory()->stalnoOtvorena()->create(['rok' => '2026-01-01']);
        $objavljena = Prilika::factory()->objavljena()->create(['rok' => '2026-01-01']);
        $arhivirana = Prilika::factory()->arhivirana()->create(['rok' => '2026-01-01']);

        $this->assertStringContainsString('Arhivirano starih nacrta: 0.', $this->arhiviraj());

        $this->assertSame(
            [StatusPrilike::Nacrt, StatusPrilike::Nacrt, StatusPrilike::Nacrt, StatusPrilike::Nacrt, StatusPrilike::Objavljeno, StatusPrilike::Arhivirano],
            array_map(fn (Prilika $prilika) => $prilika->fresh()->status, [$tacnoTrideset, $noviji, $bezRoka, $stalno, $objavljena, $arhivirana]),
        );
    }

    // Granica se pomera sa danom: nacrt koji je juče bio tačno 30 dana star sutra je 31 dan star i arhivira se; drugi prolaz iste noći ništa više ne nalazi.
    #[Test]
    public function granica_se_pomera_sa_danom_a_drugi_prolaz_nema_sta_da_arhivira(): void
    {
        $nacrt = Prilika::factory()->create(['rok' => '2026-09-30']);
        Prilika::factory()->create(['rok' => '2026-08-01']);

        $this->assertStringContainsString('Arhivirano starih nacrta: 1.', $this->arhiviraj());
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);

        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));

        $this->assertStringContainsString('Arhivirano starih nacrta: 1.', $this->arhiviraj());
        $this->assertSame(StatusPrilike::Arhivirano, $nacrt->fresh()->status);
        $this->assertStringContainsString('Arhivirano starih nacrta: 0.', $this->arhiviraj());
    }

    /** @param  array<string, mixed>  $predlog */
    private function nacrtSaPredlogom(array $predlog, array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...['rok' => null, 'predlog' => $predlog], ...$dopuna]);
    }

    // Paket 96/T2 (PITANJA-86 3): obrada rok ne upisuje u kolonu kad nacrt odbije, nego ga ostavlja u predlogu; kad je razlog „rok je prošao", datum je dokazan citatom sa strane, pa se po njemu arhivira.
    #[Test]
    public function nacrt_sa_razlogom_rok_je_prosao_se_arhivira_po_roku_iz_predloga(): void
    {
        $star = $this->nacrtSaPredlogom(['odbijeno' => 'rok je prošao', 'rok' => '2026-09-29', 'citat' => 'Rok za prijavu je 29. septembra 2026. godine']);

        $this->assertStringContainsString('Arhivirano starih nacrta: 1.', $this->arhiviraj());

        $this->assertSame(StatusPrilike::Arhivirano, $star->fresh()->status);
        $this->assertNull($star->fresh()->rok);
    }

    /** @return array<string, array{string}> */
    public static function razlozi_ciji_je_datum_samo_tvrdnja_modela(): array
    {
        return [
            'pravilo za objavu ne važi' => ['pravilo za objavu ne važi'],
            'vrsta nije potkrepljena' => ['vrsta nije potkrepljena'],
            'rok ne piše u tekstu strane' => ['rok ne piše u tekstu strane'],
            'pravilo nije potkrepljeno citatom' => ['pravilo nije potkrepljeno citatom'],
            'rok bez godine je daleko od datuma objave' => ['rok bez godine je daleko od datuma objave'],
        ];
    }

    // Ogledalo: isti star datum u predlogu uz bilo koji drugi razlog ne arhivira ništa.
    #[Test]
    #[DataProvider('razlozi_ciji_je_datum_samo_tvrdnja_modela')]
    public function drugi_razlozi_se_ne_racunaju_po_roku_iz_predloga(string $razlog): void
    {
        $nacrt = $this->nacrtSaPredlogom(['odbijeno' => $razlog, 'rok' => '2026-01-01']);

        $this->assertStringContainsString('Arhivirano starih nacrta: 0.', $this->arhiviraj());

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
    }

    // Granica ostaje trideset dana, a predlog bez ispravnog datuma, bez razloga ili za priliku koja je stalno otvorena, objavljena ili arhivirana ništa ne pokreće; upit ne puca ni na tuđem obliku.
    #[Test]
    public function ostalo_se_ne_arhivira_po_predlogu(): void
    {
        $razlog = ['odbijeno' => 'rok je prošao'];
        $nacrti = [
            'tačno trideset dana' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '2026-09-30']),
            'noviji' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '2026-10-15']),
            'bez datuma' => $this->nacrtSaPredlogom([...$razlog, 'rok' => null]),
            'datum nije datum' => $this->nacrtSaPredlogom([...$razlog, 'rok' => 'jesen']),
            'datum bez nule' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '2026-9-1']),
            'datum je broj' => $this->nacrtSaPredlogom([...$razlog, 'rok' => 20260101]),
            'prazan tekst' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '']),
            'samo godina' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '2025']),
            'bez razloga' => $this->nacrtSaPredlogom(['odbijeno' => null, 'rok' => '2026-01-01']),
            'bez predloga' => Prilika::factory()->create(['rok' => null, 'predlog' => null]),
            'stalno otvoren' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '2026-01-01'], ['rok_stalno_otvoren' => true]),
            // Ivan je posle obrade upisao rok u kolonu: važi kolona, a predlog je zastareo.
            'Ivan upisao kasniji rok' => $this->nacrtSaPredlogom([...$razlog, 'rok' => '2026-01-01'], ['rok' => '2026-12-01']),
            'objavljena' => Prilika::factory()->objavljena()->create(['rok' => null, 'predlog' => [...$razlog, 'rok' => '2026-01-01']]),
            'arhivirana' => Prilika::factory()->arhivirana()->create(['rok' => null, 'predlog' => [...$razlog, 'rok' => '2026-01-01']]),
        ];
        $pre = array_map(fn (Prilika $prilika) => $prilika->fresh()->status, $nacrti);

        $this->assertStringContainsString('Arhivirano starih nacrta: 0.', $this->arhiviraj());

        $this->assertCount(14, $nacrti);
        $this->assertEquals($pre, array_map(fn (Prilika $prilika) => $prilika->fresh()->status, $nacrti));
    }

    // Kolona „rok" ostaje prvi izbor: star rok u koloni arhivira nacrt i kad je razlog u predlogu drugi.
    #[Test]
    public function stari_rok_u_koloni_arhivira_i_kad_predlog_ima_drugi_razlog(): void
    {
        $nacrt = $this->nacrtSaPredlogom(['odbijeno' => 'pravilo za objavu ne važi', 'rok' => '2026-10-20'], ['rok' => '2026-09-01']);

        $this->assertStringContainsString('Arhivirano starih nacrta: 1.', $this->arhiviraj());

        $this->assertSame(StatusPrilike::Arhivirano, $nacrt->fresh()->status);
    }
}
