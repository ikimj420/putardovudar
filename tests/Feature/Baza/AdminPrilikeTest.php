<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\CreatePrilika;
use App\Filament\Resources\Prilike\Pages\EditPrilika;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Filament\Resources\Prilike\PrilikaResource;
use App\Models\Prilika;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class AdminPrilikeTest extends AdminBazaTestCase
{
    #[Test]
    public function prijavljen_korisnik_vidi_spisak_prilika(): void
    {
        $prilike = Prilika::factory()->count(3)->create();
        $this->actingAs(User::factory()->create());

        $this->get(PrilikaResource::getUrl('index'))->assertOk();
        Livewire::test(ListPrilike::class)->assertCanSeeTableRecords($prilike);
    }

    #[Test]
    public function prijavljen_korisnik_dodaje_priliku_a_slug_se_pravi_iz_naslova(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePrilika::class)
            ->fillForm([
                'naslov' => 'Radnik u skladištu, Niš',
                'vrsta' => VrstaPrilike::Posao,
                'kratak_opis' => 'Puno radno vreme.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $prilika = Prilika::query()->sole();

        $this->assertSame('Radnik u skladištu, Niš', $prilika->naslov);
        $this->assertSame('radnik-u-skladistu-nis', $prilika->slug);
        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
    }

    #[Test]
    public function prijavljen_korisnik_menja_priliku(): void
    {
        $prilika = Prilika::factory()->create(['naslov' => 'Stari naslov']);
        $this->actingAs(User::factory()->create());

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])
            ->fillForm(['naslov' => 'Novi naslov', 'vrsta' => VrstaPrilike::Obuka])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Novi naslov', $prilika->fresh()->naslov);
        $this->assertSame(VrstaPrilike::Obuka, $prilika->fresh()->vrsta);
    }

    // Ogledalo: isti zahtevi bez prijave završavaju na prijavi, a sa prijavom na strani.
    #[Test]
    public function neprijavljen_ne_moze_da_otvori_spisak_dodavanje_ni_izmenu(): void
    {
        $prilika = Prilika::factory()->create();
        $strane = [
            PrilikaResource::getUrl('index'),
            PrilikaResource::getUrl('create'),
            PrilikaResource::getUrl('edit', ['record' => $prilika]),
        ];

        foreach ($strane as $adresa) {
            $this->get($adresa)->assertRedirect(Filament::getLoginUrl());
        }

        $this->actingAs(User::factory()->create());

        foreach ($strane as $adresa) {
            $this->get($adresa)->assertOk();
        }
    }

    #[Test]
    public function forma_ne_pusta_objavu_bez_linka_izvora_i_kaze_zasto(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePrilika::class)
            ->fillForm([
                'naslov' => 'Radnik u skladištu, Niš',
                'vrsta' => VrstaPrilike::Posao,
                'status' => StatusPrilike::Objavljeno,
                'kratak_opis' => 'Puno radno vreme.',
                'link_izvora' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['link_izvora' => 'required'])
            ->assertSee(Prilika::PORUKA_BEZ_IZVORA);

        $this->assertSame(0, Prilika::query()->count());
    }

    // Ogledalo: isti obrazac sa linkom se objavljuje.
    #[Test]
    public function forma_objavljuje_priliku_sa_linkom_izvora(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePrilika::class)
            ->fillForm([
                'naslov' => 'Radnik u skladištu, Niš',
                'vrsta' => VrstaPrilike::Posao,
                'status' => StatusPrilike::Objavljeno,
                'kratak_opis' => 'Puno radno vreme.',
                'link_izvora' => 'https://primer.rs/konkurs',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(StatusPrilike::Objavljeno, Prilika::query()->sole()->status);
    }

    #[Test]
    public function brisanje_nije_ni_ponudjeno_ni_dozvoljeno(): void
    {
        $prilika = Prilika::factory()->create();
        $korisnik = User::factory()->create();
        $this->actingAs($korisnik);

        $this->assertTrue($korisnik->can('update', $prilika));
        $this->assertFalse($korisnik->can('delete', $prilika));
        $this->assertFalse($korisnik->can('deleteAny', Prilika::class));

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])->assertActionDoesNotExist('delete');
        Livewire::test(ListPrilike::class)->assertTableActionDoesNotExist('delete', record: $prilika)->assertTableBulkActionDoesNotExist('delete');
    }

    #[Test]
    public function natpisi_i_objasnjenja_polja_su_tacno_kako_je_odobreno(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(PrilikaResource::getUrl('create'))->assertOk()->assertSeeInOrder([
            'Naslov', 'Kratak naslov koji se vidi na spisku, npr. „Radnik u skladištu, Niš“.',
            'Slug', 'Deo adrese strane; ako ostane prazno, pravi se iz naslova.',
            'Vrsta', 'Izaberi šta je ova prilika.',
            'Status', 'Nacrt se ne vidi javno, Objavljeno se vidi do isteka roka, Arhivirano se ne vidi.',
            'Kratak opis', 'Jedna do dve rečenice za spisak, npr. „Puno radno vreme, plata po dogovoru.“',
            'Opis', 'Sve što čovek treba da zna pre prijave: uslovi, dokumenta, kako se prijavljuje.',
            'Rok', 'Poslednji dan za prijavu; ostavi prazno ako roka nema.',
            'Rok stalno otvoren', 'Uključi ako se može prijaviti uvek; tada se rok ne gleda.',
            'Mesto', 'Grad ili opština, npr. „Niš“; ostavi prazno ako nije vezano za mesto.',
            'Online', 'Uključi ako se sve radi preko interneta.',
            'Naziv izvora', 'Ko je objavio priliku, npr. „Nacionalna služba za zapošljavanje“.',
            'Link izvora', 'Adresa strane odakle je preuzeto; bez nje se prilika ne može objaviti.',
        ]);
    }

    // Ogledalo: objašnjenja polja žive na formi, ne na spisku; a spisak nosi naziv iz menija.
    #[Test]
    public function spisak_nosi_naziv_iz_menija_a_ne_objasnjenja_polja(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(PrilikaResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Prilike')
            ->assertDontSee('Izaberi šta je ova prilika.');
    }

    #[Test]
    public function naziv_u_meniju_i_nazivi_prilike_su_na_srpskom(): void
    {
        $this->assertSame('Prilike', PrilikaResource::getNavigationLabel());
        $this->assertSame('prilika', PrilikaResource::getModelLabel());
        $this->assertSame('prilike', PrilikaResource::getPluralModelLabel());
    }

    #[Test]
    public function rok_se_na_spisku_pise_kao_dan_mesec_godina(): void
    {
        Prilika::factory()->create(['rok' => '2026-10-05']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ListPrilike::class)
            ->assertSee('05.10.2026.')
            ->assertDontSee('2026-10-05')
            ->assertDontSee('Oct 5, 2026');
    }

    // Ogledalo: ista komponenta sa prijavljenim korisnikom radi.
    // Ogledalo: isti spisak sa rokom i sa „stalno otvoren" crta te redove.
    #[Test]
    public function prazna_vrednost_na_spisku_ne_crta_red(): void
    {
        $this->actingAs(User::factory()->create());

        $prilika = Prilika::factory()->create(['rok' => null, 'rok_stalno_otvoren' => false]);

        Livewire::test(ListPrilike::class)->assertSee($prilika->naslov)->assertDontSee('Rok');

        $prilika->update(['rok' => '2026-10-05', 'rok_stalno_otvoren' => true]);

        Livewire::test(ListPrilike::class)->assertSee('Rok')->assertSee('05.10.2026.')->assertSee('Rok stalno otvoren');
    }

    #[Test]
    public function neprijavljen_ne_moze_ni_direktno_do_komponente_spiska(): void
    {
        Prilika::factory()->create(['naslov' => 'Tajni naslov']);

        Livewire::test(ListPrilike::class)->assertForbidden()->assertDontSee('Tajni naslov');

        $this->actingAs(User::factory()->create());

        Livewire::test(ListPrilike::class)->assertOk()->assertSee('Tajni naslov');
    }
}
