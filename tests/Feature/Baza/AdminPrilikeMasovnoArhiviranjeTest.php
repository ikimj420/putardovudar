<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\Prilika;
use App\Models\User;
use App\Policies\PrilikaPolicy;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 86/T2: masovna radnja „Arhiviraj" radi samo nad nacrtima; objavljena prilika ne može da se arhivira masovno, a brisanja nema.
#[Group('baza')]
class AdminPrilikeMasovnoArhiviranjeTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function spisak(): Testable
    {
        return Livewire::test(ListPrilike::class);
    }

    private function stranica(Testable $spisak): ListPrilike
    {
        $stranica = $spisak->instance();

        return $stranica instanceof ListPrilike ? $stranica : throw new \LogicException('Spisak nije strana prilika.');
    }

    private function nacrt(string $naslov): Prilika
    {
        return Prilika::factory()->create(['naslov' => $naslov, 'status' => StatusPrilike::Nacrt, 'rok' => null]);
    }

    #[Test]
    public function izabrani_nacrti_postaju_arhivirani_a_neizabrani_ostaju_nacrti(): void
    {
        $prvi = $this->nacrt('Prvi');
        $drugi = $this->nacrt('Drugi');
        $neizabran = $this->nacrt('Neizabran');

        $this->spisak()->callTableBulkAction('arhiviraj', [$prvi, $drugi])->assertNotified('Arhivirano nacrta: 2.');

        $this->assertSame(StatusPrilike::Arhivirano, $prvi->fresh()->status);
        $this->assertSame(StatusPrilike::Arhivirano, $drugi->fresh()->status);
        $this->assertSame(StatusPrilike::Nacrt, $neizabran->fresh()->status);
        $this->assertSame(3, Prilika::query()->count());
    }

    // Objavljena i već arhivirana prilika u izboru se ne diraju, a broj u obaveštenju je broj stvarno arhiviranih nacrta.
    #[Test]
    public function objavljena_i_arhivirana_prilika_ostaju_kakve_jesu_a_obavestenje_broji_samo_nacrte(): void
    {
        $nacrt = $this->nacrt('Nacrt');
        $objavljena = Prilika::factory()->objavljena()->create(['naslov' => 'Objavljena', 'rok' => today()->addDays(10)->toDateString()]);
        $arhivirana = Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana']);

        $this->spisak()->callTableBulkAction('arhiviraj', [$nacrt, $objavljena, $arhivirana])->assertNotified('Arhivirano nacrta: 1.');

        $this->assertSame(StatusPrilike::Arhivirano, $nacrt->fresh()->status);
        $this->assertSame(StatusPrilike::Objavljeno, $objavljena->fresh()->status);
        $this->assertSame(StatusPrilike::Arhivirano, $arhivirana->fresh()->status);
        $this->assertTrue(Prilika::javne()->whereKey($objavljena->getKey())->exists());
    }

    // Kartica objavljene i arhivirane prilike nema polje za izbor, a kartica nacrta ima.
    #[Test]
    public function samo_nacrt_moze_da_se_izabere(): void
    {
        $nacrt = $this->nacrt('Nacrt');
        $objavljena = Prilika::factory()->objavljena()->create(['naslov' => 'Objavljena']);
        $arhivirana = Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana']);

        $tabela = $this->stranica($this->spisak())->getTable();

        $this->assertTrue($tabela->isRecordSelectable($nacrt));
        $this->assertFalse($tabela->isRecordSelectable($objavljena));
        $this->assertFalse($tabela->isRecordSelectable($arhivirana));
    }

    // Prozor pre arhiviranja kaže šta se potvrđuje, a dugme nosi ime radnje; ništa se ne menja dok se ne potvrdi.
    #[Test]
    public function prozor_kaze_sta_se_potvrdjuje_a_dugme_nosi_ime_radnje(): void
    {
        $nacrt = $this->nacrt('Nacrt');

        $spisak = $this->spisak()->mountTableBulkAction('arhiviraj', [$nacrt]);
        $akcija = $this->stranica($spisak)->getMountedAction();

        $this->assertNotNull($akcija);
        $this->assertTrue($akcija->isConfirmationRequired());
        $this->assertSame('Arhivirati izabrane nacrte?', (string) $akcija->getModalHeading());
        $this->assertSame('Arhivirano se ne objavljuje i ne ide ponovo u obradu. Objavljene prilike se ovde ne arhiviraju.', (string) $akcija->getModalDescription());
        $this->assertSame('Arhiviraj', (string) $akcija->getModalSubmitActionLabel());
        $this->assertSame('Arhiviraj', (string) $akcija->getLabel());
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
    }

    // Brava na radnji koja piše: ko ne sme da menja priliku, ne može ni da je arhivira.
    #[Test]
    public function ko_ne_sme_da_menja_priliku_ne_moze_ni_da_je_arhivira(): void
    {
        $nacrt = $this->nacrt('Nacrt');

        Gate::policy(Prilika::class, PrilikaPolicyBezIzmene::class);

        $this->spisak()->callTableBulkAction('arhiviraj', [$nacrt]);

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
    }

    // Brisanja nema: u masovnim radnjama je samo „Arhiviraj", a posle njega svi redovi su i dalje u bazi.
    #[Test]
    public function brisanja_nema(): void
    {
        $nacrt = $this->nacrt('Nacrt');

        $spisak = $this->spisak()->assertTableBulkActionExists('arhiviraj')->assertTableBulkActionDoesNotExist('delete');

        $this->assertSame(['arhiviraj'], array_keys($this->stranica($spisak)->getTable()->getFlatBulkActions()));

        $spisak->callTableBulkAction('arhiviraj', [$nacrt]);

        $this->assertDatabaseHas('prilike', ['id' => $nacrt->getKey(), 'status' => StatusPrilike::Arhivirano->value]);
    }

    // Isti pravilo na jednom mestu: model arhivira samo nacrt.
    #[Test]
    public function model_arhivira_samo_nacrt(): void
    {
        $nacrt = $this->nacrt('Nacrt');
        $objavljena = Prilika::factory()->objavljena()->create(['naslov' => 'Objavljena']);
        $arhivirana = Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana']);

        $this->assertTrue($nacrt->arhiviraj());
        $this->assertFalse($objavljena->arhiviraj());
        $this->assertFalse($arhivirana->arhiviraj());

        $this->assertSame(StatusPrilike::Arhivirano, $nacrt->fresh()->status);
        $this->assertSame(StatusPrilike::Objavljeno, $objavljena->fresh()->status);
    }
}

// Politika za probu brave: sve kao PrilikaPolicy, osim što niko ne sme da menja priliku.
class PrilikaPolicyBezIzmene extends PrilikaPolicy
{
    public function update(User $korisnik, Prilika $prilika): bool
    {
        return false;
    }
}
