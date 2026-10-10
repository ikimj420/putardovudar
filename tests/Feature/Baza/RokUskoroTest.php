<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 61 T1: opseg rokUskoro je jedino mesto koje kaže šta je „zatvara se uskoro": rok od danas do danas + 14 dana, oba kraja uključena, po zoni aplikacije.
#[Group('baza')]
class RokUskoroTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{0: string|null, 1: bool}> rok, da li ulazi */
    public static function rokovi(): array
    {
        return [
            'danas' => ['2026-10-10', true],
            'sutra' => ['2026-10-11', true],
            'usred opsega' => ['2026-10-17', true],
            'danas + 13' => ['2026-10-23', true],
            'danas + 14' => ['2026-10-24', true],
            'danas + 15' => ['2026-10-25', false],
            'mnogo kasnije' => ['2026-12-01', false],
            'juče' => ['2026-10-09', false],
            'prošao pre mesec' => ['2026-09-10', false],
            'bez roka' => [null, false],
        ];
    }

    #[Test]
    #[DataProvider('rokovi')]
    public function rok_od_danas_do_danas_plus_cetrnaest_ulazi_a_sve_ostalo_ne(?string $rok, bool $ulazi): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => $rok]);
        // Prilika van opsega mora da postoji da bi merilo imalo šta da isključi.
        Prilika::factory()->objavljena()->create(['rok' => '2027-03-01']);

        $this->assertSame($ulazi, Prilika::query()->rokUskoro()->whereKey($prilika->id)->exists(), (string) $rok);
        $this->assertSame($ulazi, Prilika::javne()->rokUskoro()->whereKey($prilika->id)->exists(), (string) $rok);
    }

    // Broj dana je jedan i ima vrednost 14; opseg ga čita odatle, pa se granica pomera sa njim.
    #[Test]
    public function broj_dana_je_na_jednom_mestu_i_granica_ga_prati(): void
    {
        $this->assertSame(14, Prilika::DANA_USKORO);

        $granica = Prilika::factory()->objavljena()->create(['rok' => today()->addDays(Prilika::DANA_USKORO)->toDateString()]);
        $iza = Prilika::factory()->objavljena()->create(['rok' => today()->addDays(Prilika::DANA_USKORO + 1)->toDateString()]);

        $this->assertSame([$granica->id], Prilika::javne()->rokUskoro()->pluck('id')->all());
        $this->assertNotContains($iza->id, Prilika::query()->rokUskoro()->pluck('id')->all());

        // Nigde drugde u kodu ili pogledima nema ispisanog broja dana uz „uskoro".
        $nalazi = [];
        foreach ([base_path('app'), resource_path('views')] as $koren) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($koren, \FilesystemIterator::SKIP_DOTS)) as $fajl) {
                $sadrzaj = (string) file_get_contents($fajl->getPathname());

                if (preg_match('/addDays\(\s*14\s*\)|DANA_USKORO\s*=\s*14/', $sadrzaj) === 1) {
                    $nalazi[] = str_replace(base_path().'/', '', $fajl->getPathname());
                }
            }
        }

        $this->assertSame(['app/Models/Prilika.php'], $nalazi);
    }

    // Stalno otvorene prijave nemaju rok koji važi, pa ne ulaze ni kad im je upisan rok u opsegu.
    #[Test]
    public function stalno_otvoreno_ne_ulazi_a_isti_rok_bez_te_oznake_ulazi(): void
    {
        $stalno = Prilika::factory()->objavljena()->create(['rok' => '2026-10-15', 'rok_stalno_otvoren' => true]);
        $obicno = Prilika::factory()->objavljena()->create(['rok' => '2026-10-15', 'rok_stalno_otvoren' => false]);

        $this->assertSame([$obicno->id], Prilika::javne()->rokUskoro()->pluck('id')->all());
        $this->assertNotContains($stalno->id, Prilika::query()->rokUskoro()->pluck('id')->all());
    }

    // Javne prilike su samo objavljene; nacrt i arhivirana sa rokom u opsegu ne ulaze, a objavljena sa istim rokom ulazi.
    #[Test]
    public function nacrt_i_arhivirano_ne_ulaze_kroz_javne_a_objavljeno_ulazi(): void
    {
        $nacrt = Prilika::factory()->create(['status' => StatusPrilike::Nacrt, 'rok' => '2026-10-15']);
        $arhivirano = Prilika::factory()->create(['status' => StatusPrilike::Arhivirano, 'rok' => '2026-10-15']);
        $objavljeno = Prilika::factory()->objavljena()->create(['rok' => '2026-10-15']);

        $this->assertSame([$objavljeno->id], Prilika::javne()->rokUskoro()->pluck('id')->all());
        // Sam opseg ne gleda status: to radi javne(), na jednom mestu.
        $this->assertEqualsCanonicalizing([$nacrt->id, $arhivirano->id, $objavljeno->id], Prilika::query()->rokUskoro()->pluck('id')->all());
    }

    // „Danas" je po Beogradu: u 00:30 po Beogradu je UTC još prethodni dan, ali granice se računaju od beogradskog dana.
    #[Test]
    public function danas_je_po_beogradu_a_ne_po_utc(): void
    {
        foreach (['2026-10-10', '2026-10-11', '2026-10-25', '2026-10-26'] as $rok) {
            Prilika::factory()->objavljena()->create(['naslov' => 'rok '.$rok, 'rok' => $rok]);
        }

        $this->travelTo(Carbon::parse('2026-10-10 22:30:00', 'UTC'));

        $this->assertSame('2026-10-10', now('UTC')->toDateString());
        $this->assertSame(['rok 2026-10-11', 'rok 2026-10-25'], Prilika::query()->rokUskoro()->orderBy('rok')->pluck('naslov')->all());
    }
}
