<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Filament\Resources\Vodici\Pages\CreateVodic;
use App\Filament\Resources\Vodici\Pages\EditVodic;
use App\Filament\Resources\Vodici\Pages\ListVodici;
use App\Filament\Resources\Vodici\VodicResource;
use App\Models\Sablon;
use App\Models\User;
use App\Models\Vodic;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class AdminVodiciTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function spisak_vodica_su_kartice_sa_statusom_brojem_koraka_i_dugmetom_uredi(): void
    {
        $vodic = Vodic::factory()->create(['naslov' => 'Kako napisati CV', 'koraci' => ['A', 'B', 'C']]);
        $bezKoraka = Vodic::factory()->create(['naslov' => 'Bez koraka', 'koraci' => null]);

        $html = Livewire::test(ListVodici::class)->assertCanSeeTableRecords([$vodic, $bezKoraka])->html();

        $this->assertSame(2, substr_count($html, 'role="listitem"'));
        $this->assertStringContainsString('--cols-xl: repeat(3, minmax(0, 1fr))', $html);
        $this->assertStringNotContainsString('<table', $html);

        $spisak = Livewire::test(ListVodici::class);
        $spisak->assertSeeInOrder(['Kako napisati CV', 'Nacrt', 'Koraka', '3', 'Uredi']);
        $spisak->assertTableActionHasUrl('edit', VodicResource::getUrl('edit', ['record' => $vodic]), record: $vodic);
    }

    // Ogledalo: vodič bez koraka ne crta red „Koraka".
    #[Test]
    public function vodic_bez_koraka_nema_red_koraka_na_kartici(): void
    {
        Vodic::factory()->create(['koraci' => null]);

        Livewire::test(ListVodici::class)->assertDontSeeHtml('>Koraka<')->assertSeeHtml('>Izmenjen<');
    }

    #[Test]
    public function pretraga_nalazi_vodic_po_naslovu(): void
    {
        $cv = Vodic::factory()->create(['naslov' => 'Kako napisati CV']);
        $razgovor = Vodic::factory()->create(['naslov' => 'Priprema za razgovor']);

        Livewire::test(ListVodici::class)
            ->searchTable('razgovor')
            ->assertCanSeeTableRecords([$razgovor])
            ->assertCanNotSeeTableRecords([$cv]);
    }

    #[Test]
    public function dodavanje_vodica_pravi_slug_iz_naslova_i_cuva_korake_i_belesku(): void
    {
        Livewire::test(CreateVodic::class)
            ->fillForm([
                'naslov' => 'Kako napisati CV', 'kratak_opis' => 'Kratko.', 'tekst' => 'Ceo tekst.',
                'koraci' => [['korak' => 'Prvi korak'], ['korak' => 'Drugi korak']], 'beleska' => 'Samo za admina.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vodic = Vodic::query()->sole();

        $this->assertSame('kako-napisati-cv', $vodic->slug);
        $this->assertSame(['Prvi korak', 'Drugi korak'], $vodic->koraci);
        $this->assertSame('Samo za admina.', $vodic->beleska);
        $this->assertSame(StatusObjave::Nacrt, $vodic->status);
    }

    // Ogledalo prethodnog: vodič bez koraka se snima (prazan red koraka nije podrazumevan).
    #[Test]
    public function vodic_bez_koraka_se_snima(): void
    {
        Livewire::test(CreateVodic::class)
            ->fillForm(['naslov' => 'Bez koraka', 'kratak_opis' => 'Kratko.', 'tekst' => 'Tekst.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertEmpty(Vodic::query()->sole()->koraci);
    }

    #[Test]
    public function naslov_kratak_opis_i_tekst_su_obavezni(): void
    {
        Livewire::test(CreateVodic::class)
            ->fillForm(['naslov' => '', 'kratak_opis' => '', 'tekst' => ''])
            ->call('create')
            ->assertHasFormErrors(['naslov' => 'required', 'kratak_opis' => 'required', 'tekst' => 'required']);

        $this->assertSame(0, Vodic::query()->count());
    }

    #[Test]
    public function izmena_vodica_menja_tekst_korake_i_status(): void
    {
        $vodic = Vodic::factory()->create();

        Livewire::test(EditVodic::class, ['record' => $vodic->getKey()])
            ->fillForm(['naslov' => 'Novi naslov', 'koraci' => [['korak' => 'Jedini korak']], 'status' => StatusObjave::Objavljeno])
            ->call('save')
            ->assertHasNoFormErrors();

        $posle = $vodic->fresh();

        $this->assertSame('Novi naslov', $posle->naslov);
        $this->assertSame(['Jedini korak'], $posle->koraci);
        $this->assertSame(StatusObjave::Objavljeno, $posle->status);
        $this->assertSame(1, Vodic::javni()->count());
    }

    #[Test]
    public function status_nudi_samo_nacrt_i_objavljeno(): void
    {
        $this->assertSame(['nacrt', 'objavljeno'], array_map(fn (StatusObjave $status) => $status->value, StatusObjave::cases()));
        $this->assertSame(['Nacrt', 'Objavljeno'], array_map(fn (StatusObjave $status) => $status->getLabel(), StatusObjave::cases()));
    }

    #[Test]
    public function brisanje_nije_ni_ponudjeno_ni_dozvoljeno(): void
    {
        $vodic = Vodic::factory()->create();
        $korisnik = User::factory()->create();

        $this->assertTrue($korisnik->can('update', $vodic));
        $this->assertFalse($korisnik->can('delete', $vodic));
        $this->assertFalse($korisnik->can('deleteAny', Vodic::class));

        Livewire::test(ListVodici::class)->assertTableActionDoesNotExist('delete', record: $vodic)->assertTableBulkActionDoesNotExist('delete');
        Livewire::test(EditVodic::class, ['record' => $vodic->getKey()])->assertActionDoesNotExist('delete');
    }

    // Ogledalo: isti zahtevi bez prijave završavaju na prijavi, a sa prijavom na strani.
    #[Test]
    public function neprijavljen_ne_moze_do_spiska_dodavanja_ni_izmene(): void
    {
        $vodic = Vodic::factory()->create();
        $strane = [VodicResource::getUrl('index'), VodicResource::getUrl('create'), VodicResource::getUrl('edit', ['record' => $vodic])];

        auth()->logout();

        foreach ($strane as $adresa) {
            $this->get($adresa)->assertRedirect(Filament::getLoginUrl());
        }

        $this->actingAs(User::factory()->create());

        foreach ($strane as $adresa) {
            $this->get($adresa)->assertOk();
        }
    }

    // Paket 56: primer se snima uz vodič i može da se obriše (prazno polje je vodič bez primera).
    #[Test]
    public function primer_se_snima_menja_i_brise(): void
    {
        Livewire::test(CreateVodic::class)
            ->fillForm(['naslov' => 'Sa primerom', 'kratak_opis' => 'Kratko.', 'tekst' => 'Tekst.', 'primer' => "Poštovani,\n\nPrimer pisma."])
            ->call('create')
            ->assertHasNoFormErrors();

        $vodic = Vodic::query()->sole();
        $this->assertSame("Poštovani,\n\nPrimer pisma.", $vodic->primer);

        Livewire::test(EditVodic::class, ['record' => $vodic->getKey()])->fillForm(['primer' => 'Novi primer.'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Novi primer.', $vodic->fresh()->primer);

        Livewire::test(EditVodic::class, ['record' => $vodic->getKey()])->fillForm(['primer' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($vodic->fresh()->primer);
    }

    // Paket 56 T2: vodič ima najviše jedan šablon, može i bez; izbor nudi sve šablone (i nacrte), a javnost ga vidi tek kad je objavljen.
    #[Test]
    public function sablon_se_bira_menja_i_skida_sa_vodica(): void
    {
        $molba = Sablon::factory()->create(['naslov' => 'Molba']);
        $zahtev = Sablon::factory()->objavljen()->create(['naslov' => 'Zahtev']);

        Livewire::test(CreateVodic::class)
            ->fillForm(['naslov' => 'Kako napisati molbu', 'kratak_opis' => 'Kratko.', 'tekst' => 'Tekst.', 'sablon_id' => $molba->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $vodic = Vodic::query()->sole();
        $this->assertSame($molba->getKey(), $vodic->sablon_id);

        Livewire::test(EditVodic::class, ['record' => $vodic->getKey()])->fillForm(['sablon_id' => $zahtev->getKey()])->call('save')->assertHasNoFormErrors();
        $this->assertSame($zahtev->getKey(), $vodic->fresh()->sablon_id);

        Livewire::test(EditVodic::class, ['record' => $vodic->getKey()])->fillForm(['sablon_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($vodic->fresh()->sablon_id);
    }

    // Ogledalo: vodič se snima i bez šablona, a izbor ne prima šablon koji ne postoji.
    #[Test]
    public function vodic_se_snima_bez_sablona_a_nepostojeci_sablon_se_odbija(): void
    {
        Livewire::test(CreateVodic::class)
            ->fillForm(['naslov' => 'Bez šablona', 'kratak_opis' => 'Kratko.', 'tekst' => 'Tekst.'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertNull(Vodic::query()->sole()->sablon_id);

        Livewire::test(CreateVodic::class)
            ->fillForm(['naslov' => 'Sa lažnim', 'kratak_opis' => 'Kratko.', 'tekst' => 'Tekst.', 'sablon_id' => 999999])
            ->call('create')
            ->assertHasFormErrors(['sablon_id']);
        $this->assertSame(1, Vodic::query()->count());
    }

    #[Test]
    public function natpisi_i_objasnjenja_polja_su_na_srpskom(): void
    {
        $this->get(VodicResource::getUrl('create'))->assertOk()->assertSeeInOrder([
            'Naslov', 'Naslov vodiča koji se vidi na spisku', 'Slug', 'Deo adrese strane',
            'Kratak opis', 'Jedna do dve rečenice za spisak', 'Tekst', 'Ceo vodič, običnim rečima',
            'Koraci', 'Spisak koraka redom', 'Primer', 'Gotov primer (pismo, e-pošta, CV…)', 'Šablon', 'Šablon po kome pomoćnik piše tekst za ovaj vodič', 'Beleška', 'Samo za tebe, ne vidi se na sajtu',
            'Status', 'Nacrt se ne vidi javno, a Objavljeno se vidi na sajtu.',
        ]);
    }
}
