<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Filament\Resources\Vodici\Pages\CreateVodic;
use App\Filament\Resources\Vodici\Pages\EditVodic;
use App\Filament\Resources\Vodici\Pages\ListVodici;
use App\Filament\Resources\Vodici\VodicResource;
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

    #[Test]
    public function natpisi_i_objasnjenja_polja_su_na_srpskom(): void
    {
        $this->get(VodicResource::getUrl('create'))->assertOk()->assertSeeInOrder([
            'Naslov', 'Naslov vodiča koji se vidi na spisku', 'Slug', 'Deo adrese strane',
            'Kratak opis', 'Jedna do dve rečenice za spisak', 'Tekst', 'Ceo vodič, običnim rečima',
            'Koraci', 'Spisak koraka redom', 'Beleška', 'Samo za tebe, ne vidi se na sajtu',
            'Status', 'Nacrt se ne vidi javno, a Objavljeno se vidi na sajtu.',
        ]);
    }
}
