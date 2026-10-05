<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\CreatePrilika;
use App\Filament\Resources\Prilike\PrilikaResource;
use App\Models\Prilika;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class PorukeProvereUAdminuSuNaSrpskomTest extends AdminBazaTestCase
{
    // Jezik se zadaje ovde, da test ne zavisi od jezika u lokalnom .env.
    protected function setUp(): void
    {
        $this->postaviOkruzenje('APP_LOCALE', 'sr_Latn');

        parent::setUp();
    }

    #[Test]
    public function prazan_naslov_u_formi_daje_poruku_na_srpskom(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePrilika::class)
            ->fillForm(['naslov' => '', 'vrsta' => VrstaPrilike::Posao, 'kratak_opis' => 'Puno radno vreme.'])
            ->call('create')
            ->assertHasFormErrors(['naslov' => 'required'])
            ->assertSee('Polje naslov je obavezno.')
            ->assertDontSee('is required');

        $this->assertSame(0, Prilika::query()->count());
    }

    // Ogledalo: ista forma sa naslovom nema te poruke i čuva priliku.
    #[Test]
    public function popunjen_naslov_nema_poruku(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePrilika::class)
            ->fillForm(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'kratak_opis' => 'Puno radno vreme.'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertDontSee('Polje naslov je obavezno.');

        $this->assertSame(1, Prilika::query()->count());
    }

    #[Test]
    public function i_ostala_pravila_forme_pisu_na_srpskom(): void
    {
        Prilika::factory()->create(['slug' => 'zauzet-slug']);
        $this->actingAs(User::factory()->create());

        Livewire::test(CreatePrilika::class)
            ->fillForm([
                'naslov' => 'Nova', 'slug' => 'zauzet-slug', 'vrsta' => VrstaPrilike::Posao,
                'kratak_opis' => 'Opis.', 'link_izvora' => 'nije link',
            ])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique', 'link_izvora' => 'url'])
            ->assertSee('Polje slug već postoji.')
            ->assertSee('Format polja link izvora ne važi.')
            ->assertDontSee('has already been taken')
            ->assertDontSee('must be a valid URL');
    }

    // Bez novalidate pregledač blokira slanje i pokaže svoju poruku na jeziku pregledača, pa srpska sa servera ne stigne do čoveka.
    #[Test]
    public function forme_za_dodavanje_i_izmenu_ne_prepustaju_proveru_pregledacu(): void
    {
        $prilika = Prilika::factory()->create();
        $this->actingAs(User::factory()->create());

        foreach ([PrilikaResource::getUrl('create'), PrilikaResource::getUrl('edit', ['record' => $prilika])] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            preg_match('/<form[^>]*\bid="form"[^>]*>/', $html, $forma);
            preg_match('/<input[^>]*wire:model="data\.naslov"[^>]*>/', $html, $naslov);

            $this->assertNotSame([], $forma, $adresa.': nema forme');
            $this->assertStringContainsString('novalidate', $forma[0], $adresa);
            // Ogledalo: pravilo ostaje, uklonjeno je samo blokiranje u pregledaču.
            $this->assertNotSame([], $naslov, $adresa.': nema polja za naslov');
            $this->assertStringContainsString('required', $naslov[0], $adresa);
        }
    }
}
