<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class JavnePrilikeTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function javna_je_objavljena_prilika_kojoj_rok_nije_prosao(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));

        Prilika::factory()->objavljena()->create(['naslov' => 'objavljena, budući rok', 'rok' => '2026-10-06']);
        Prilika::factory()->objavljena()->create(['naslov' => 'objavljena, rok danas', 'rok' => '2026-10-05']);
        Prilika::factory()->objavljena()->create(['naslov' => 'objavljena, bez roka', 'rok' => null]);
        Prilika::factory()->objavljena()->stalnoOtvorena()->create(['naslov' => 'objavljena, stalno otvorena, prošao rok', 'rok' => '2026-01-01']);

        Prilika::factory()->objavljena()->create(['naslov' => 'objavljena, rok juče', 'rok' => '2026-10-04']);
        Prilika::factory()->create(['naslov' => 'nacrt, budući rok', 'rok' => '2026-10-06']);
        Prilika::factory()->arhivirana()->create(['naslov' => 'arhivirana, budući rok', 'rok' => '2026-10-06']);
        Prilika::factory()->stalnoOtvorena()->create(['naslov' => 'nacrt, stalno otvoren']);

        $javne = Prilika::javne()->pluck('naslov')->all();

        $this->assertEqualsCanonicalizing([
            'objavljena, budući rok',
            'objavljena, rok danas',
            'objavljena, bez roka',
            'objavljena, stalno otvorena, prošao rok',
        ], $javne);
    }

    // Ogledalo: isti zapisi, jedan dan kasnije — rok od juče više nije javan, a ostali su.
    #[Test]
    public function sutradan_rok_koji_je_bio_danas_vise_nije_javan(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'rok 05.10.', 'rok' => '2026-10-05']);
        Prilika::factory()->objavljena()->create(['naslov' => 'rok 06.10.', 'rok' => '2026-10-06']);

        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        $this->assertEqualsCanonicalizing(['rok 05.10.', 'rok 06.10.'], Prilika::javne()->pluck('naslov')->all());

        $this->travelTo(Carbon::create(2026, 10, 6, 12, 0, 0, 'Europe/Belgrade'));
        $this->assertSame(['rok 06.10.'], Prilika::javne()->pluck('naslov')->all());
    }

    #[Test]
    public function danas_se_menja_u_ponoc_po_beogradu_a_ne_po_utc(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'rok 05.10.', 'rok' => '2026-10-05']);

        $this->travelTo(Carbon::create(2026, 10, 5, 23, 59, 0, 'Europe/Belgrade'));
        $this->assertSame(['rok 05.10.'], Prilika::javne()->pluck('naslov')->all());

        $this->travelTo(Carbon::create(2026, 10, 6, 0, 0, 0, 'Europe/Belgrade'));
        $this->assertSame([], Prilika::javne()->pluck('naslov')->all());
    }

    // U 22:30 po UTC datum je još 05.10, ali u Beogradu je već 06.10: po UTC bi rok od 05.10. bio javan.
    #[Test]
    public function u_isto_vreme_utc_pokazuje_jedan_dan_ranije_a_javno_se_meri_po_beogradu(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'rok 05.10.', 'rok' => '2026-10-05']);
        Prilika::factory()->objavljena()->create(['naslov' => 'rok 06.10.', 'rok' => '2026-10-06']);

        $this->travelTo(Carbon::parse('2026-10-05 22:30:00', 'UTC'));

        $this->assertSame('2026-10-05', now('UTC')->toDateString());
        $this->assertSame(['rok 06.10.'], Prilika::javne()->pluck('naslov')->all());
    }
}
