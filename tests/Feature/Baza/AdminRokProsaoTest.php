<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\Prilika;
use App\Models\User;
use App\Support\PrikazPrilike;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// „Rok prošao" na kartici u adminu i javne prilike pitaju isto mesto (Prilika::rokProsao); „danas" je 10.10.2026. po Beogradu.
#[Group('baza')]
class AdminRokProsaoTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{?string, bool, bool}> */
    public static function slucajevi(): array
    {
        // rok, stalno otvoreno, da li je rok prošao
        return [
            'rok juče' => ['2026-10-09', false, true],
            'rok pre mesec dana' => ['2026-09-10', false, true],
            'rok danas' => ['2026-10-10', false, false],
            'rok sutra' => ['2026-10-11', false, false],
            'bez roka' => [null, false, false],
            'stalno otvoreno sa starim rokom' => ['2026-01-01', true, false],
            'stalno otvoreno bez roka' => [null, true, false],
        ];
    }

    private function prilika(?string $rok, bool $stalno, StatusPrilike $status = StatusPrilike::Objavljeno): Prilika
    {
        return Prilika::factory()->create(['naslov' => 'Prilika za proveru roka', 'rok' => $rok, 'rok_stalno_otvoren' => $stalno, 'status' => $status]);
    }

    #[Test]
    #[DataProvider('slucajevi')]
    public function kartica_pise_rok_prosao_samo_kad_je_rok_prosao(?string $rok, bool $stalno, bool $prosao): void
    {
        $this->prilika($rok, $stalno);

        $spisak = Livewire::test(ListPrilike::class)->assertSee('Prilika za proveru roka');

        $prosao ? $spisak->assertSee(PrikazPrilike::ROK_PROSAO) : $spisak->assertDontSee(PrikazPrilike::ROK_PROSAO);
    }

    // Isto važi za svaki status: nacrt kome je rok prošao je ono što Ivan treba da vidi pre objave.
    #[Test]
    #[DataProvider('statusi')]
    public function oznaka_se_pise_za_svaki_status(StatusPrilike $status): void
    {
        $this->prilika('2026-10-09', false, $status);

        Livewire::test(ListPrilike::class)->assertSee('Rok prošao');
    }

    /** @return array<string, array{StatusPrilike}> */
    public static function statusi(): array
    {
        return ['nacrt' => [StatusPrilike::Nacrt], 'objavljeno' => [StatusPrilike::Objavljeno], 'arhivirano' => [StatusPrilike::Arhivirano]];
    }

    // Sve odjednom: oznaka je tačno na kartici kojoj je rok prošao, ne na susednoj.
    #[Test]
    public function oznaka_je_na_pravoj_kartici_a_ne_na_susednoj(): void
    {
        $rezultat = [];
        foreach (self::slucajevi() as $naziv => [$rok, $stalno]) {
            $rezultat[$naziv] = Prilika::factory()->create(['naslov' => $naziv, 'rok' => $rok, 'rok_stalno_otvoren' => $stalno]);
        }

        $html = Livewire::test(ListPrilike::class)->set('tableRecordsPerPage', 25)->html();
        $kartice = preg_split('/(?=wire:key="[^"]*\.table\.records\.\d+")/', $html) ?: [];
        $nadjeno = 0;

        foreach (self::slucajevi() as $naziv => [, , $prosao]) {
            $kartica = collect($kartice)->first(fn (string $deo) => preg_match('/>\s*'.preg_quote($naziv, '/').'\s*</u', $deo) === 1);
            $this->assertNotNull($kartica, $naziv.': nema kartice');
            $nadjeno++;

            $this->assertSame($prosao, str_contains((string) $kartica, 'Rok prošao'), $naziv);
        }

        $this->assertSame(count(self::slucajevi()), $nadjeno);
    }

    // Admin i sajt pitaju isto: objavljena prilika je javna tačno kad joj rok nije prošao.
    #[Test]
    public function javne_i_oznaka_se_slazu_za_svaki_slucaj(): void
    {
        foreach (self::slucajevi() as $naziv => [$rok, $stalno, $prosao]) {
            Prilika::factory()->objavljena()->create(['naslov' => $naziv, 'rok' => $rok, 'rok_stalno_otvoren' => $stalno]);
        }

        $this->assertSame(7, Prilika::query()->count());
        $this->assertEqualsCanonicalizing(
            array_keys(array_filter(self::slucajevi(), fn (array $s) => $s[2])),
            Prilika::rokProsao()->pluck('naslov')->all(),
        );

        foreach (Prilika::query()->get() as $prilika) {
            $this->assertSame(self::slucajevi()[$prilika->naslov][2], $prilika->rokJeProsao(), $prilika->naslov.' (model)');
            $this->assertSame($prilika->rokJeProsao(), ! Prilika::javne()->whereKey($prilika->getKey())->exists(), $prilika->naslov.' (javne)');
        }

        $this->assertEqualsCanonicalizing(
            array_keys(array_filter(self::slucajevi(), fn (array $s) => ! $s[2])),
            Prilika::javne()->pluck('naslov')->all(),
        );
    }

    // Isti odgovor kad stiže kao kolona uz upit spiska i kad se pita bazu za jedan zapis.
    #[Test]
    public function kolona_uz_upit_i_pitanje_za_jedan_zapis_daju_isti_odgovor(): void
    {
        foreach (self::slucajevi() as $naziv => [$rok, $stalno]) {
            Prilika::factory()->create(['naslov' => $naziv, 'rok' => $rok, 'rok_stalno_otvoren' => $stalno]);
        }

        $saKolonom = Prilika::saOznakomRoka(Prilika::query())->get();

        $this->assertCount(7, $saKolonom);

        foreach ($saKolonom as $prilika) {
            $this->assertArrayHasKey('rok_prosao', $prilika->getAttributes());
            $this->assertSame(self::slucajevi()[$prilika->naslov][2], $prilika->rokJeProsao(), $prilika->naslov);
            $this->assertSame($prilika->rokJeProsao(), Prilika::query()->findOrFail($prilika->getKey())->rokJeProsao(), $prilika->naslov);
        }
    }

    // „Danas" se menja u ponoć po Beogradu, a ne po UTC; oznaka i javne se menjaju zajedno.
    #[Test]
    public function rok_od_danas_postaje_prosao_u_ponoc_po_beogradu(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-10-10']);

        $this->travelTo(Carbon::create(2026, 10, 10, 23, 59, 0, 'Europe/Belgrade'));
        $this->assertFalse($prilika->rokJeProsao());
        $this->assertTrue(Prilika::javne()->whereKey($prilika->getKey())->exists());

        $this->travelTo(Carbon::create(2026, 10, 11, 0, 0, 0, 'Europe/Belgrade'));
        $this->assertTrue($prilika->rokJeProsao());
        $this->assertFalse(Prilika::javne()->whereKey($prilika->getKey())->exists());

        // 22:30 po UTC je već 00:30 sledećeg dana u Beogradu.
        $this->travelTo(Carbon::parse('2026-10-10 22:30:00', 'UTC'));
        $this->assertSame('2026-10-10', now('UTC')->toDateString());
        $this->assertTrue($prilika->rokJeProsao());
        Livewire::test(ListPrilike::class)->assertSee('Rok prošao');
    }

    // Kartica ne pita bazu za svaki zapis posebno: isti broj upita za jedan i za osam zapisa.
    #[Test]
    public function broj_upita_ne_raste_sa_brojem_kartica(): void
    {
        $this->prilika('2026-10-09', false);
        $jedan = $this->upitaSaRokom();

        foreach (range(1, 7) as $broj) {
            Prilika::factory()->create(['naslov' => 'Dodatna '.$broj, 'rok' => '2026-10-09', 'rok_stalno_otvoren' => false]);
        }

        $this->assertSame(8, Prilika::query()->count());
        $this->assertGreaterThan(0, $jedan);
        $this->assertSame($jedan, $this->upitaSaRokom());
    }

    private function upitaSaRokom(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListPrilike::class)->assertSee('Rok prošao');

        $upita = collect(DB::getQueryLog())->filter(fn (array $upit) => str_contains($upit['query'], 'rok_stalno_otvoren` = 0') || str_contains($upit['query'], 'rok_stalno_otvoren = 0'))->count();
        DB::disableQueryLog();

        return $upita;
    }

    // Vrsta je pod svojim imenom, a ne nasumična iz fabrike: crvena je samo oznaka roka.
    #[Test]
    public function oznaka_je_crvena_a_status_i_vrsta_to_nisu(): void
    {
        Prilika::factory()->create(['naslov' => 'Za boju', 'vrsta' => VrstaPrilike::Posao, 'status' => StatusPrilike::Nacrt, 'rok' => '2026-10-09', 'rok_stalno_otvoren' => false]);

        $html = Livewire::test(ListPrilike::class)->html();

        $this->assertMatchesRegularExpression('/fi-color-danger[^>]*>\s*(?:<[^>]+>\s*)*Rok prošao/s', $html);
        $this->assertDoesNotMatchRegularExpression('/fi-color-danger[^>]*>\s*(?:<[^>]+>\s*)*Nacrt/s', $html);
        $this->assertDoesNotMatchRegularExpression('/fi-color-danger[^>]*>\s*(?:<[^>]+>\s*)*'.preg_quote(VrstaPrilike::Posao->getLabel(), '/').'/s', $html);
        // Ogledalo: obe oznake su na kartici, pa pretraga nije prošla nad praznim.
        $this->assertStringContainsString('Nacrt', strip_tags($html));
        $this->assertStringContainsString(VrstaPrilike::Posao->getLabel(), strip_tags($html));
    }
}
