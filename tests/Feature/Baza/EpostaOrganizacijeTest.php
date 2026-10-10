<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaOrganizacije;
use App\Filament\Resources\Organizacije\Pages\CreateOrganizacija;
use App\Filament\Resources\Organizacije\Pages\EditOrganizacija;
use App\Models\Organizacija;
use App\Models\User;
use App\Support\EpostaAdresa;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class EpostaOrganizacijeTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, array{string}> */
    public static function neispravne(): array
    {
        return [
            'bez monkeya' => ['nije-adresa'],
            'bez domena' => ['ivan@'],
            'bez imena' => ['@primer.rs'],
            'razmak u imenu' => ['iv an@primer.rs'],
            'razmak u domenu' => ['ivan@pri mer.rs'],
            'dva monkeya' => ['ivan@@primer.rs'],
            'bez tačke u domenu' => ['ivan@primer'],
            'dve tačke zaredom' => ['ivan..prezime@primer.rs'],
            'tačka na početku' => ['.ivan@primer.rs'],
            'tačka pre monkeya' => ['ivan.@primer.rs'],
            'dopuna adrese' => ['ivan@primer.rs?cc=drugi@primer.rs'],
            'upitnik u imenu' => ['ivan?cc@primer.rs'],
            'navodnici u imenu' => ['"ivan"@primer.rs'],
            'dve adrese' => ['ivan@primer.rs, drugi@primer.rs'],
            'skripta' => ['javascript:alert(1)'],
            'domen sa crticom na kraju' => ['ivan@primer-.rs'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function ispravne(): array
    {
        return [
            'obična' => ['info@primer.rs'],
            'sa tačkom i plusom' => ['ime.prezime+oglas@primer.org.rs'],
            'poddomen' => ['kontakt@mail.primer.rs'],
            'sa brojem i crticom' => ['ured-1@primer-2.rs'],
        ];
    }

    private function organizacija(): Organizacija
    {
        return Organizacija::factory()->create(['naziv' => 'Fondacija Primer', 'eposta' => 'staro@primer.rs']);
    }

    #[Test]
    #[DataProvider('ispravne')]
    public function ispravna_adresa_se_snima_kroz_izmenu(string $adresa): void
    {
        $organizacija = $this->organizacija();

        Livewire::test(EditOrganizacija::class, ['record' => $organizacija->getKey()])
            ->fillForm(['eposta' => $adresa])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($adresa, $organizacija->refresh()->eposta);
    }

    // Glavna tvrdnja paketa: neispravna adresa se ne snima, a stara ostaje.
    #[Test]
    #[DataProvider('neispravne')]
    public function neispravna_adresa_se_ne_snima_kroz_izmenu(string $adresa): void
    {
        $organizacija = $this->organizacija();

        Livewire::test(EditOrganizacija::class, ['record' => $organizacija->getKey()])
            ->fillForm(['eposta' => $adresa])
            ->call('save')
            ->assertHasFormErrors(['eposta']);

        $this->assertSame('staro@primer.rs', $organizacija->refresh()->eposta);
    }

    #[Test]
    #[DataProvider('neispravne')]
    public function neispravna_adresa_ne_pravi_organizaciju_kroz_dodavanje(string $adresa): void
    {
        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Nova', 'vrsta' => VrstaOrganizacije::Udruzenje, 'kratak_opis' => 'Kratko.', 'eposta' => $adresa])
            ->call('create')
            ->assertHasFormErrors(['eposta']);

        $this->assertSame(0, Organizacija::query()->count());
    }

    #[Test]
    public function dodavanje_snima_adresu_a_prazno_polje_je_dozvoljeno(): void
    {
        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Sa adresom', 'vrsta' => VrstaOrganizacije::Udruzenje, 'kratak_opis' => 'Kratko.', 'eposta' => 'info@primer.rs'])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreateOrganizacija::class)
            ->fillForm(['naziv' => 'Bez adrese', 'vrsta' => VrstaOrganizacije::Udruzenje, 'kratak_opis' => 'Kratko.', 'eposta' => ''])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('info@primer.rs', Organizacija::query()->where('naziv', 'Sa adresom')->sole()->eposta);
        $this->assertTrue(blank(Organizacija::query()->where('naziv', 'Bez adrese')->sole()->eposta));
    }

    // Polje u adminu nosi oznaku i jednu rečenicu objašnjenja.
    #[Test]
    public function polje_u_adminu_nosi_oznaku_i_objasnjenje(): void
    {
        Livewire::test(EditOrganizacija::class, ['record' => $this->organizacija()->getKey()])
            ->assertSee('E-pošta')
            ->assertSee('Adresa za kontakt, npr. „info@primer.rs“; ostavi prazno ako je nema.');
    }

    // Tip polja daje telefonu tastaturu sa znakom „@“; ogledalo: telefon i sajt nisu tog tipa.
    #[Test]
    public function polje_eposte_je_tipa_email_a_ostala_polja_nisu(): void
    {
        $html = Livewire::test(EditOrganizacija::class, ['record' => $this->organizacija()->getKey()])->html();

        $this->assertSame(1, substr_count($html, 'type="email"'));
        $this->assertMatchesRegularExpression('/<input[^>]*id="[^"]*eposta"[^>]*type="email"|<input[^>]*type="email"[^>]*id="[^"]*eposta"/', $html);
    }

    #[Test]
    public function strana_organizacije_pise_eposta_kao_vezu_za_slanje_poste(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create(['eposta' => 'info@primer.rs']);

        $html = (string) $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent();

        $this->assertStringContainsString('E-pošta: <a href="mailto:info@primer.rs">info@primer.rs</a>', $html);
    }

    // Pilula je flex pa gubi razmak između teksta i veze; izmereno u pregledaču („E-pošta:info@...“ bez razmaka).
    #[Test]
    public function pilula_ima_razmak_izmedju_natpisa_i_veze(): void
    {
        $stil = (string) file_get_contents(public_path('css/javno.css'));

        $this->assertMatchesRegularExpression('/\.meta-stavka \{[^}]*display: inline-flex;[^}]*gap: 0\.3em;/s', $stil);
    }

    // Ogledalo: bez adrese nema ni reda ni veze.
    #[Test]
    public function organizacija_bez_eposte_nema_red_ni_vezu(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create(['eposta' => null]);

        $html = (string) $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('E-pošta', $html);
        $this->assertStringNotContainsString('mailto:', $html);
    }

    // Adresa u bazi može da stigne i mimo admina; sajt od nje ne sme da napravi opasnu vezu.
    #[Test]
    #[DataProvider('neispravne')]
    public function neispravna_adresa_iz_baze_se_ne_ispisuje_kao_veza(string $adresa): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create();
        Organizacija::query()->whereKey($organizacija->getKey())->update(['eposta' => $adresa]);

        $html = (string) $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('E-pošta', $html);
    }

    // Isto pravilo na oba mesta: ono što admin primi, sajt ispiše; ono što admin odbije, sajt ne ispiše.
    #[Test]
    public function admin_i_sajt_imaju_isto_pitanje_o_ispravnosti(): void
    {
        foreach (self::ispravne() as [$adresa]) {
            $this->assertTrue(EpostaAdresa::jeIspravna($adresa), $adresa);
        }

        foreach (self::neispravne() as [$adresa]) {
            $this->assertFalse(EpostaAdresa::jeIspravna($adresa), $adresa);
        }

        $this->assertFalse(EpostaAdresa::jeIspravna(null));
        $this->assertFalse(EpostaAdresa::jeIspravna('   '));
        $this->assertFalse(EpostaAdresa::jeIspravna(str_repeat('a', 250).'@primer.rs'));
    }

    #[Test]
    public function adresa_sa_plusom_ostaje_neizmenjena_u_vezi(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create();
        Organizacija::query()->whereKey($organizacija->getKey())->update(['eposta' => 'a+b@primer.rs']);

        $html = (string) $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent();

        $this->assertStringContainsString('href="mailto:a+b@primer.rs"', $html);
    }
}
