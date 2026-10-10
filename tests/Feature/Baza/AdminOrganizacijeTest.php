<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Filament\Resources\Organizacije\OrganizacijaResource;
use App\Filament\Resources\Organizacije\Pages\CreateOrganizacija;
use App\Filament\Resources\Organizacije\Pages\EditOrganizacija;
use App\Filament\Resources\Organizacije\Pages\ListOrganizacije;
use App\Models\Organizacija;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class AdminOrganizacijeTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function spisak_su_kartice_sa_statusom_vrstom_mestom_telefonom_sajtom_i_dugmetom_uredi(): void
    {
        $organizacija = Organizacija::factory()->create(['naziv' => 'Fondacija Primer', 'vrsta' => VrstaOrganizacije::Fondacija, 'mesto' => 'Beograd', 'telefon' => '011/123-456', 'sajt' => 'https://primer.rs/']);
        $druga = Organizacija::factory()->create(['naziv' => 'Druga', 'mesto' => null, 'telefon' => null, 'sajt' => null]);

        $html = Livewire::test(ListOrganizacije::class)->assertCanSeeTableRecords([$organizacija, $druga])->html();

        $this->assertSame(2, substr_count($html, 'role="listitem"'));
        $this->assertStringContainsString('--cols-xl: repeat(3, minmax(0, 1fr))', $html);
        $this->assertStringNotContainsString('<table', $html);

        $spisak = Livewire::test(ListOrganizacije::class);
        $spisak->assertSeeInOrder(['Fondacija Primer', 'Nacrt', 'Fondacija', 'Mesto', 'Beograd', 'Telefon', '011/123-456', 'Sajt', 'https://primer.rs/', 'Uredi']);
        $spisak->assertTableActionHasUrl('edit', OrganizacijaResource::getUrl('edit', ['record' => $organizacija]), record: $organizacija);
    }

    // Ogledalo: prazna vrednost ne crta red.
    #[Test]
    public function organizacija_bez_mesta_telefona_i_sajta_nema_te_redove(): void
    {
        Organizacija::factory()->create(['mesto' => null, 'telefon' => null, 'sajt' => null]);

        Livewire::test(ListOrganizacije::class)->assertDontSeeHtml('>Mesto<')->assertDontSeeHtml('>Telefon<')->assertDontSeeHtml('>Sajt<');
    }

    #[Test]
    public function pretraga_nalazi_organizaciju_po_nazivu(): void
    {
        $prva = Organizacija::factory()->create(['naziv' => 'Fondacija Tempus']);
        $druga = Organizacija::factory()->create(['naziv' => 'Krovna organizacija']);

        Livewire::test(ListOrganizacije::class)->searchTable('Krovna')->assertCanSeeTableRecords([$druga])->assertCanNotSeeTableRecords([$prva]);
    }

    #[Test]
    public function dodavanje_pravi_slug_cuva_usluge_i_nova_organizacija_je_nacrt(): void
    {
        Livewire::test(CreateOrganizacija::class)
            ->fillForm([
                'naziv' => 'Nacionalna služba', 'vrsta' => VrstaOrganizacije::JavnaInstitucija, 'kratak_opis' => 'Kratko.',
                'usluge' => [['usluga' => 'Savetovanje'], ['usluga' => 'Konkursi']], 'beleska' => 'Samo za admina.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $organizacija = Organizacija::query()->sole();

        $this->assertSame('nacionalna-sluzba', $organizacija->slug);
        $this->assertSame(['Savetovanje', 'Konkursi'], $organizacija->usluge);
        $this->assertSame(StatusObjave::Nacrt, $organizacija->status);
    }

    #[Test]
    public function forma_ne_pusta_objavu_bez_sajta_i_kaze_zasto(): void
    {
        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Bez sajta', 'vrsta' => VrstaOrganizacije::Drugo, 'kratak_opis' => 'Kratko.', 'status' => StatusObjave::Objavljeno, 'sajt' => null])
            ->call('create')
            ->assertHasFormErrors(['sajt' => 'required'])
            ->assertSee(Organizacija::PORUKA_BEZ_SAJTA);

        $this->assertSame(0, Organizacija::query()->count());
    }

    // Ogledalo: isti obrazac sa sajtom se objavljuje, a nacrt bez sajta se snima.
    #[Test]
    public function forma_objavljuje_sa_sajtom_a_nacrt_bez_sajta_snima(): void
    {
        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Sa sajtom', 'vrsta' => VrstaOrganizacije::Drugo, 'kratak_opis' => 'Kratko.', 'status' => StatusObjave::Objavljeno, 'sajt' => 'https://primer.rs'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Nacrt bez sajta', 'vrsta' => VrstaOrganizacije::Drugo, 'kratak_opis' => 'Kratko.', 'sajt' => null])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Organizacija::javne()->count());
        $this->assertSame(2, Organizacija::query()->count());
    }

    #[Test]
    public function sajt_mora_da_bude_adresa(): void
    {
        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Pogrešan sajt', 'vrsta' => VrstaOrganizacije::Drugo, 'kratak_opis' => 'Kratko.', 'sajt' => 'nije adresa'])
            ->call('create')
            ->assertHasFormErrors(['sajt']);
    }

    #[Test]
    public function izmena_menja_podatke_usluge_i_status(): void
    {
        $organizacija = Organizacija::factory()->create(['sajt' => 'https://primer.rs']);

        Livewire::test(EditOrganizacija::class, ['record' => $organizacija->getKey()])
            ->fillForm(['naziv' => 'Novi naziv', 'usluge' => [['usluga' => 'Jedina usluga']], 'status' => StatusObjave::Objavljeno])
            ->call('save')
            ->assertHasNoFormErrors();

        $posle = $organizacija->fresh();

        $this->assertSame('Novi naziv', $posle->naziv);
        $this->assertSame(['Jedina usluga'], $posle->usluge);
        $this->assertSame(1, Organizacija::javne()->count());
    }

    #[Test]
    public function brisanje_nije_ni_ponudjeno_ni_dozvoljeno(): void
    {
        $organizacija = Organizacija::factory()->create();
        $korisnik = User::factory()->create();

        $this->assertTrue($korisnik->can('update', $organizacija));
        $this->assertFalse($korisnik->can('delete', $organizacija));
        $this->assertFalse($korisnik->can('deleteAny', Organizacija::class));

        Livewire::test(ListOrganizacije::class)->assertTableActionDoesNotExist('delete', record: $organizacija)->assertTableBulkActionDoesNotExist('delete');
        Livewire::test(EditOrganizacija::class, ['record' => $organizacija->getKey()])->assertActionDoesNotExist('delete');
    }

    // Ogledalo: isti zahtevi bez prijave završavaju na prijavi, a sa prijavom na strani.
    #[Test]
    public function neprijavljen_ne_moze_do_spiska_dodavanja_ni_izmene(): void
    {
        $organizacija = Organizacija::factory()->create();
        $strane = [OrganizacijaResource::getUrl('index'), OrganizacijaResource::getUrl('create'), OrganizacijaResource::getUrl('edit', ['record' => $organizacija])];

        auth()->logout();

        foreach ($strane as $adresa) {
            $this->get($adresa)->assertRedirect(Filament::getLoginUrl());
        }

        $this->actingAs(User::factory()->create());

        foreach ($strane as $adresa) {
            $this->get($adresa)->assertOk();
        }
    }

    #[Test]
    public function natpisi_i_objasnjenja_polja_su_na_srpskom(): void
    {
        $this->get(OrganizacijaResource::getUrl('create'))->assertOk()->assertSeeInOrder([
            'Naziv', 'Pun naziv organizacije', 'Slug', 'Deo adrese strane', 'Vrsta', 'Izaberi kakva je organizacija.',
            'Kratak opis', 'Jedna do dve rečenice za spisak', 'Opis', 'Čime se organizacija bavi', 'Mesto', 'Gde se organizacija nalazi',
            'Online', 'Uključi ako se usluge dobijaju i preko interneta.', 'Telefon', 'Broj za kontakt', 'Sajt', 'Adresa zvaničnog sajta; bez nje se organizacija ne može objaviti.',
            'Usluge', 'Šta organizacija nudi', 'Beleška', 'Samo za tebe, ne vidi se na sajtu',
            'Status', 'Nacrt se ne vidi javno, a Objavljeno se vidi na sajtu; za objavu je potreban sajt.',
        ]);
    }
}
