<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class PrilikaUBaziTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function migracija_prolazi_nad_testnom_bazom_i_pravi_tabelu_sa_tacno_ovim_kolonama(): void
    {
        $this->assertSame('putardovudar_test', DB::getDatabaseName());
        $this->assertTrue(DB::table('migrations')->where('migration', 'like', '%create_prilike_table')->exists());
        $this->assertTrue(DB::table('migrations')->where('migration', 'like', '%add_obrada_nacrta_to_prilike_table')->exists());

        $kolone = array_column(Schema::getColumns('prilike'), 'name');

        $this->assertEqualsCanonicalizing([
            'id', 'naslov', 'slug', 'vrsta', 'status', 'kratak_opis', 'opis', 'rok', 'rok_stalno_otvoren',
            'mesto', 'online', 'naziv_izvora', 'link_izvora', 'created_at', 'updated_at',
            'obradeno_at', 'objavio', 'razlog_objave', 'predlog',
        ], $kolone);
    }

    #[Test]
    public function rok_mesto_opis_i_izvor_mogu_da_budu_prazni_a_naslov_slug_vrsta_status_i_kratak_opis_ne_mogu(): void
    {
        $mogu = ['opis', 'rok', 'mesto', 'naziv_izvora', 'link_izvora', 'obradeno_at', 'objavio', 'razlog_objave', 'predlog'];
        $ne_mogu = ['naslov', 'slug', 'vrsta', 'status', 'kratak_opis', 'rok_stalno_otvoren', 'online'];

        $prazno = collect(Schema::getColumns('prilike'))->pluck('nullable', 'name');

        foreach ($mogu as $kolona) {
            $this->assertTrue($prazno[$kolona], $kolona.' treba da može da bude prazna');
        }

        foreach ($ne_mogu as $kolona) {
            $this->assertFalse($prazno[$kolona], $kolona.' ne sme da bude prazna');
        }
    }

    #[Test]
    public function vrste_su_tacno_ovako_i_tim_redom(): void
    {
        $this->assertSame(
            ['Posao', 'Praksa', 'Stipendija', 'Konkurs', 'Obuka', 'Drugo'],
            array_map(fn (VrstaPrilike $vrsta) => $vrsta->getLabel(), VrstaPrilike::cases()),
        );
    }

    #[Test]
    public function statusi_su_tacno_ovako_i_tim_redom(): void
    {
        $this->assertSame(
            ['Nacrt', 'Objavljeno', 'Arhivirano'],
            array_map(fn (StatusPrilike $status) => $status->getLabel(), StatusPrilike::cases()),
        );
    }

    #[Test]
    public function fabrika_pravi_ispravnu_priliku_koja_se_cuva_i_cita_nazad(): void
    {
        $upisana = Prilika::factory()->create();
        $procitana = Prilika::query()->findOrFail($upisana->id);

        $this->assertNotSame('', $procitana->naslov);
        $this->assertNotSame('', $procitana->slug);
        $this->assertNotSame('', $procitana->kratak_opis);
        $this->assertInstanceOf(VrstaPrilike::class, $procitana->vrsta);
        $this->assertSame(StatusPrilike::Nacrt, $procitana->status);
        $this->assertFalse($procitana->rok_stalno_otvoren);
        $this->assertFalse($procitana->online);
        $this->assertNotFalse(filter_var($procitana->link_izvora, FILTER_VALIDATE_URL));
    }

    // Ogledalo: prazan rok je dozvoljen, ali zadat rok se čita nazad kao datum, ne kao tekst.
    #[Test]
    public function prilika_moze_bez_roka_a_zadat_rok_se_cita_kao_datum(): void
    {
        $bezRoka = Prilika::factory()->create(['rok' => null]);
        $saRokom = Prilika::factory()->create(['rok' => '2026-10-05']);

        $rok = $saRokom->fresh()->rok;

        $this->assertNull($bezRoka->fresh()->rok);
        $this->assertInstanceOf(Carbon::class, $rok);
        $this->assertTrue($rok->isSameDay(Carbon::create(2026, 10, 5)));
    }

    #[Test]
    public function slug_se_pravi_iz_naslova_a_isti_naslov_dobija_redni_broj(): void
    {
        $prva = Prilika::factory()->create(['naslov' => 'Radnik u skladištu, Niš', 'slug' => '']);
        $druga = Prilika::factory()->create(['naslov' => 'Radnik u skladištu, Niš', 'slug' => '']);

        $this->assertSame('radnik-u-skladistu-nis', $prva->slug);
        $this->assertSame('radnik-u-skladistu-nis-2', $druga->slug);
    }

    // Ogledalo: slug koji je zadat ostaje, a baza ne dozvoljava dva ista.
    #[Test]
    public function zadat_slug_ostaje_a_dva_ista_sluga_se_odbijaju(): void
    {
        $prilika = Prilika::factory()->create(['slug' => 'moj-slug']);

        $this->assertSame('moj-slug', $prilika->fresh()->slug);

        $this->expectException(QueryException::class);
        Prilika::factory()->create(['slug' => 'moj-slug']);
    }
}
