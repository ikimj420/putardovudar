<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
}
