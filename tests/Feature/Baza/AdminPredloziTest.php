<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPredloga;
use App\Filament\Resources\Predlozi\Pages\ListPredlozi;
use App\Filament\Resources\Predlozi\PredlogResource;
use App\Models\Predlog;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 79/T2: admin vidi predloge posetilaca kao kartice, označava ih pregledanim i briše; predlog se u adminu ne pravi.
#[Group('baza')]
class AdminPredloziTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function admin_vidi_link_status_napomenu_i_datum_pa_dugmad_tim_redom(): void
    {
        $this->travelTo('2026-10-30 12:00:00');
        $predlog = Predlog::factory()->create(['link' => 'https://primer.rs/konkurs-za-stipendije', 'napomena' => 'Prijave su do 15. novembra.']);

        Livewire::test(ListPredlozi::class)
            ->assertCanSeeTableRecords([$predlog])
            ->assertSeeInOrder(['https://primer.rs/konkurs-za-stipendije', 'Nov', 'Napomena', 'Prijave su do 15. novembra.', 'Stiglo', '30.10.2026.', 'Pregledano', 'Obriši']);
    }

    // Ogledalo: predlog bez napomene ne crta ni red „Napomena".
    #[Test]
    public function prazna_napomena_ne_crta_red(): void
    {
        $sa = Predlog::factory()->create(['napomena' => 'Ima napomenu']);
        $bez = Predlog::factory()->create(['napomena' => null]);

        $spisak = Livewire::test(ListPredlozi::class)->assertCanSeeTableRecords([$sa, $bez]);
        $this->assertSame(1, substr_count($spisak->html(), '>Napomena<'));
        $this->assertSame(2, substr_count($spisak->html(), '>Stiglo<'));
    }

    #[Test]
    public function link_je_veza_koja_se_otvara_u_novom_prozoru(): void
    {
        Predlog::factory()->create(['link' => 'https://primer.rs/posao']);

        Livewire::test(ListPredlozi::class)->assertSeeHtml('href="https://primer.rs/posao"')->assertSeeHtml('target="_blank"');
    }

    // Ogledalo: šta nije http ili https (ako je nekako stiglo u bazu) se vidi kao tekst, ali nije veza.
    #[Test]
    public function neispravan_link_u_bazi_se_prikazuje_ali_nije_veza(): void
    {
        Predlog::factory()->create(['link' => 'javascript:alert(1)']);

        Livewire::test(ListPredlozi::class)->assertSee('javascript:alert(1)')->assertDontSeeHtml('href="javascript:');
    }

    #[Test]
    public function pregledano_menja_status_a_dugme_nestaje(): void
    {
        $nov = Predlog::factory()->create();

        Livewire::test(ListPredlozi::class)
            ->assertTableActionVisible('pregledano', $nov)
            ->callTableAction('pregledano', $nov)
            ->assertTableActionHidden('pregledano', $nov);

        $this->assertSame(StatusPredloga::Pregledan, $nov->fresh()->status);
    }

    // Ogledalo: već pregledan predlog nema dugme „Pregledano", a drugi nov predlog ostaje nov kad se označi prvi.
    #[Test]
    public function pregledan_predlog_nema_dugme_a_drugi_nov_ostaje_nov(): void
    {
        $pregledan = Predlog::factory()->pregledan()->create();
        $prvi = Predlog::factory()->create();
        $drugi = Predlog::factory()->create();

        Livewire::test(ListPredlozi::class)
            ->assertTableActionHidden('pregledano', $pregledan)
            ->assertTableActionVisible('pregledano', $prvi)
            ->callTableAction('pregledano', $prvi);

        $this->assertSame(StatusPredloga::Pregledan, $prvi->fresh()->status);
        $this->assertSame(StatusPredloga::Nov, $drugi->fresh()->status);
        $this->assertSame(StatusPredloga::Pregledan, $pregledan->fresh()->status);
    }

    #[Test]
    public function brisanje_uklanja_samo_taj_predlog(): void
    {
        $prvi = Predlog::factory()->create();
        $drugi = Predlog::factory()->create();

        Livewire::test(ListPredlozi::class)->callTableAction('delete', $prvi);

        $this->assertModelMissing($prvi);
        $this->assertModelExists($drugi);
    }

    #[Test]
    public function filter_po_statusu_razdvaja_nove_od_pregledanih(): void
    {
        $nov = Predlog::factory()->create();
        $pregledan = Predlog::factory()->pregledan()->create();

        Livewire::test(ListPredlozi::class)
            ->filterTable('status', StatusPredloga::Nov->value)
            ->assertCanSeeTableRecords([$nov])
            ->assertCanNotSeeTableRecords([$pregledan]);
    }

    // Predlog se ne pravi ni ne menja u adminu: jedina strana je spisak, a „Novi predlog" ne postoji.
    #[Test]
    public function u_adminu_nema_pravljenja_ni_izmene_predloga(): void
    {
        $this->assertSame(['index'], array_keys(PredlogResource::getPages()));
        $this->assertFalse(PredlogResource::canCreate());

        Livewire::test(ListPredlozi::class)->assertDontSee('Novi predlog')->assertDontSee('Nov predlog');
    }

    // Ogledalo: neprijavljen posetilac ne vidi spisak.
    #[Test]
    public function neprijavljen_ne_vidi_spisak_predloga(): void
    {
        $url = PredlogResource::getUrl();
        Predlog::factory()->create(['link' => 'https://primer.rs/tajna']);

        auth()->logout();
        $this->flushSession();

        $this->get($url)->assertRedirect()->assertDontSee('https://primer.rs/tajna');
    }
}
