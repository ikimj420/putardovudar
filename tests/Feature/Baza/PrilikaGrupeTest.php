<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\CreatePrilika;
use App\Filament\Resources\Prilike\Pages\EditPrilika;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\Prilika;
use App\Models\User;
use App\Support\PrikazPrilike;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 39: kome je prilika namenjena. Jedna ili više grupa, ili nijedna (tada prilika radi kao do sada).
#[Group('baza')]
class PrilikaGrupeTest extends AdminBazaTestCase
{
    use VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, array{Grupa}> */
    public static function grupe(): array
    {
        return array_combine(array_map(fn (Grupa $grupa) => $grupa->value, Grupa::cases()), array_map(fn (Grupa $grupa) => [$grupa], Grupa::cases()));
    }

    // Nazivi su iz paketa 39, doslovno, tim redosledom.
    #[Test]
    public function grupe_su_osam_i_imaju_nazive_iz_paketa(): void
    {
        $this->assertSame(
            ['Osnovci', 'Srednjoškolci', 'Studenti', 'Master i doktorski studenti', 'Nezaposleni mladi', 'Povratnici iz inostranstva', 'Organizacije', 'Romi i Romkinje'],
            array_map(fn (Grupa $grupa) => $grupa->getLabel(), Grupa::cases()),
        );
    }

    #[Test]
    public function grupe_se_cuvaju_kao_vrednosti_u_redosledu_enuma_bez_ponavljanja_i_bez_nepoznatih(): void
    {
        $prilika = Prilika::factory()->create(['grupe' => ['romi', 'studenti', 'studenti', 'nepoznata', 5]]);

        $this->assertSame(['studenti', 'romi'], $prilika->fresh()->grupe);
        $this->assertSame([Grupa::Studenti, Grupa::Romi], $prilika->fresh()->izabraneGrupe());
    }

    // Ogledalo: prilika bez grupa (nikad postavljene, prazna lista ili samo nepoznate) ostaje bez grupa.
    #[Test]
    public function prilika_bez_grupa_ostaje_bez_grupa(): void
    {
        foreach ([null, [], ['nepoznata']] as $unos) {
            $prilika = Prilika::factory()->create(['grupe' => $unos]);

            $this->assertNull($prilika->fresh()->grupe, json_encode($unos));
            $this->assertSame([], $prilika->fresh()->izabraneGrupe());
            $this->assertNull(PrikazPrilike::grupe($prilika->fresh()));
        }

        $this->assertNull(Prilika::factory()->create()->fresh()->grupe);
    }

    #[Test]
    #[DataProvider('grupe')]
    public function opseg_vraca_samo_prilike_koje_su_namenjene_toj_grupi(Grupa $grupa): void
    {
        $naslov = 'Prilika '.$grupa->value;
        Prilika::factory()->zaGrupe($grupa)->create(['naslov' => $naslov]);
        Prilika::factory()->zaGrupe($grupa, ...array_values(array_filter(Grupa::cases(), fn (Grupa $druga) => $druga !== $grupa)))->create(['naslov' => 'Sve grupe '.$grupa->value]);
        // Ogledala: prilika bez grupa i prilika samo za druge grupe ne ulaze.
        Prilika::factory()->create(['naslov' => 'Bez grupa']);
        Prilika::factory()->zaGrupe(...array_values(array_filter(Grupa::cases(), fn (Grupa $druga) => $druga !== $grupa)))->create(['naslov' => 'Samo druge '.$grupa->value]);

        $this->assertEqualsCanonicalizing([$naslov, 'Sve grupe '.$grupa->value], Prilika::zaGrupu($grupa)->pluck('naslov')->all());
    }

    #[Test]
    public function admin_dodaje_priliku_sa_vise_grupa_a_bez_grupa_radi_kao_do_sada(): void
    {
        Livewire::test(CreatePrilika::class)
            ->fillForm(['naslov' => 'Sa grupama', 'vrsta' => VrstaPrilike::Stipendija, 'kratak_opis' => 'Kratko.', 'grupe' => ['nezaposleni-mladi', 'studenti']])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreatePrilika::class)
            ->fillForm(['naslov' => 'Bez grupa', 'vrsta' => VrstaPrilike::Posao, 'kratak_opis' => 'Kratko.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['studenti', 'nezaposleni-mladi'], Prilika::query()->where('naslov', 'Sa grupama')->sole()->grupe);
        $this->assertNull(Prilika::query()->where('naslov', 'Bez grupa')->sole()->grupe);
    }

    #[Test]
    public function admin_menja_i_skida_grupe(): void
    {
        $prilika = Prilika::factory()->zaGrupe(Grupa::Osnovci)->create();

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])
            ->assertSchemaStateSet(['grupe' => [Grupa::Osnovci]])
            ->fillForm(['grupe' => ['srednjoskolci', 'romi']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['srednjoskolci', 'romi'], $prilika->fresh()->grupe);

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])->fillForm(['grupe' => []])->call('save')->assertHasNoFormErrors();

        $this->assertNull($prilika->fresh()->grupe);
    }

    // Polje nudi tačno osam grupa (ništa drugo nije za izbor) i dozvoljava više izbora.
    #[Test]
    public function polje_nudi_tacno_osam_grupa_i_vise_izbora(): void
    {
        $prilika = Prilika::factory()->create();

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])
            ->assertFormFieldExists('grupe', fn (Select $polje) => $polje->isMultiple()
                && $polje->getOptions() === array_combine(array_map(fn (Grupa $grupa) => $grupa->value, Grupa::cases()), array_map(fn (Grupa $grupa) => $grupa->getLabel(), Grupa::cases())));
    }

    #[Test]
    public function polje_u_adminu_nosi_oznaku_i_objasnjenje_i_sve_grupe_za_izbor(): void
    {
        $prilika = Prilika::factory()->create();

        $stranica = Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])
            ->assertSee('Kome je namenjeno')
            ->assertSee('Izaberi jednu ili više grupa kojima je prilika namenjena; ostavi prazno ako grupa nije poznata.');

        foreach (Grupa::cases() as $grupa) {
            $stranica->assertSee($grupa->getLabel());
        }
    }

    #[Test]
    public function kartica_u_adminu_pise_grupe_a_bez_grupa_nema_red(): void
    {
        Prilika::factory()->zaGrupe(Grupa::Studenti, Grupa::NezaposleniMladi)->create(['naslov' => 'Sa grupama']);

        Livewire::test(ListPrilike::class)->assertSeeInOrder(['Sa grupama', 'Kome je namenjeno', 'Studenti, Nezaposleni mladi']);

        // Ogledalo: kartica bez grupa ne crta red.
        Prilika::query()->delete();
        Prilika::factory()->create(['naslov' => 'Bez grupa']);

        Livewire::test(ListPrilike::class)->assertSee('Bez grupa')->assertDontSee('Kome je namenjeno');
    }

    #[Test]
    public function sajt_pise_grupe_na_kartici_i_na_strani_prilike(): void
    {
        $prilika = Prilika::factory()->objavljena()->zaGrupe(Grupa::MasterDoktorski, Grupa::Povratnici)->create(['naslov' => 'Za magistre']);

        $spisak = $this->vidljivTekst((string) $this->get(route('prilike.index'))->assertOk()->getContent());
        $strana = $this->vidljivTekst((string) $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent());

        $this->assertStringContainsString('Kome je namenjeno: Master i doktorski studenti, Povratnici iz inostranstva', $spisak);
        $this->assertStringContainsString('Kome je namenjeno: Master i doktorski studenti, Povratnici iz inostranstva', $strana);
        $this->assertSame('Master i doktorski studenti, Povratnici iz inostranstva', PrikazPrilike::grupe($prilika));

        // Pilula nosi klasu koja je u stilu (prelama se u redove), i na kartici i na strani.
        foreach ([route('prilike.index'), route('prilike.show', $prilika->slug)] as $adresa) {
            $this->assertStringContainsString('<span class="meta-stavka grupe">Kome je namenjeno: Master i doktorski studenti, Povratnici iz inostranstva</span>', (string) $this->get($adresa)->getContent());
        }
    }

    // Ogledalo: prilika bez grupa ne piše ništa o grupama ni na kartici ni na strani.
    #[Test]
    public function sajt_bez_grupa_ne_pise_nista_o_grupama(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Bez grupa']);

        foreach ([route('prilike.index'), route('prilike.show', $prilika->slug)] as $adresa) {
            $html = (string) $this->get($adresa)->assertOk()->getContent();

            $this->assertStringContainsString('Bez grupa', $html);
            $this->assertStringNotContainsString('Kome je namenjeno', $html);
            $this->assertStringNotContainsString('meta-stavka grupe', $html);
        }
    }
}
