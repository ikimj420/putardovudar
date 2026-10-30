<?php

namespace Tests\Feature\Baza;

use App\Filament\Resources\Prilike\Pages\EditPrilika;
use App\Models\Prilika;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 85: šta je dnevna provera uradila sa prilikom vidi se u adminu, uz prilikom, rečima i sa rečenicom sa strane. Tekstovi su predlog (PITANJA-85.md), pa se ovde porede doslovno.
#[Group('baza')]
class AdminProveraSajtaTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::create(2026, 10, 30, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function izmena(Prilika $prilika): Testable
    {
        return Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()]);
    }

    #[Test]
    public function prilika_vracena_u_nacrt_kaze_zasto_i_sta_pise_na_strani(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-11-15']);
        $prilika->vratiUNacrt('strana piše da je poziv zatvoren', 'Konkurs je istekao 2.09.2026.');

        $this->izmena($prilika)
            ->assertSee('Provera sajta')
            ->assertSee('Šta je dnevna provera našla na strani izvora.')
            ->assertFormFieldIsDisabled('predlog.provera')
            ->assertFormSet(['predlog.provera' => 'Vraćeno u nacrt 30.10.2026.: strana piše da je poziv zatvoren. Na strani: „Konkurs je istekao 2.09.2026.“']);
    }

    #[Test]
    public function prilika_kojoj_je_promenjen_rok_kaze_sa_kog_na_koji_i_sta_pise_na_strani(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-11-02']);
        $prilika->promeniRok(Carbon::create(2026, 11, 15), 'Prijava na konkurse produžava se do 15. novembra 2026. godine');

        $this->izmena($prilika)
            ->assertFormSet(['predlog.provera' => 'Rok promenjen 30.10.2026.: sa 02.11.2026. na 15.11.2026. Na strani: „Prijava na konkurse produžava se do 15. novembra 2026. godine“']);
    }

    #[Test]
    public function prilika_bez_roka_koja_je_dobila_rok_ne_pise_sa_kog_roka(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => null]);
        $prilika->promeniRok(Carbon::create(2026, 11, 15), 'Prijava se vrši do 15. novembra 2026.');

        $this->izmena($prilika)->assertFormSet(['predlog.provera' => 'Rok promenjen 30.10.2026.: na 15.11.2026. Na strani: „Prijava se vrši do 15. novembra 2026.“']);
    }

    // Ogledalo: prilika koju provera nije dirala nema ni polje, ni natpis.
    #[Test]
    public function prilika_koju_provera_nije_dirala_nema_polje(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-11-15', 'predlog' => ['pravilo' => 5, 'model' => 'qwen2.5:7b']]);

        $this->izmena($prilika)
            ->assertFormFieldIsHidden('predlog.provera')
            ->assertDontSee('Provera sajta');
    }
}
