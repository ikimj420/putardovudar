<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Filament\Resources\Organizacije\OrganizacijaResource;
use App\Filament\Resources\Prilike\PrilikaResource;
use App\Filament\Resources\Vodici\VodicResource;
use App\Filament\Widgets\NacrtiZaPregled;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\User;
use App\Models\Vodic;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class AdminPocetnaNacrtiTest extends AdminBazaTestCase
{
    use VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    // Različiti brojevi po vrsti (2, 3, 1), da zamena dve kartice ne prođe neprimećena.
    private function napraviSadrzaj(): void
    {
        Prilika::factory()->count(2)->create(['status' => StatusPrilike::Nacrt]);
        Prilika::factory()->objavljena()->create();
        Prilika::factory()->arhivirana()->create();

        Vodic::factory()->count(3)->create();
        Vodic::factory()->objavljen()->count(2)->create();

        Organizacija::factory()->create();
        Organizacija::factory()->objavljena()->count(4)->create();
    }

    private function pocetna(): string
    {
        return (string) $this->get(Filament::getUrl())->assertOk()->getContent();
    }

    // Adresa kartice sa tim natpisom, onako kako je čovek dobija klikom.
    private function adresaKartice(string $html, string $natpis): string
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8"?>'.$html);

        $nalaz = (new DOMXPath($dokument))->query('//a[contains(normalize-space(.), "'.$natpis.'")]');

        $this->assertSame(1, $nalaz->length, 'kartica „'.$natpis.'“ mora biti tačno jedna veza');

        $veza = $nalaz->item(0);
        $this->assertInstanceOf(DOMElement::class, $veza);

        return $veza->getAttribute('href');
    }

    #[Test]
    public function kartice_pokazuju_broj_nacrta_prilika_vodica_i_organizacija(): void
    {
        $this->napraviSadrzaj();

        $this->assertStringContainsString(
            'Prilike u nacrtu 2 Otvori spisak nacrta Vodiči u nacrtu 3 Otvori spisak nacrta Organizacije u nacrtu 1 Otvori spisak nacrta',
            $this->vidljivTekst($this->pocetna()),
        );
    }

    // Ogledalo: objavljeno i arhivirano se ne broji, pa bez nacrta sve tri kartice pišu nulu.
    #[Test]
    public function bez_nacrta_sve_tri_kartice_pisu_nulu(): void
    {
        Prilika::factory()->objavljena()->create();
        Prilika::factory()->arhivirana()->create();
        Vodic::factory()->objavljen()->create();
        Organizacija::factory()->objavljena()->create();

        $this->assertStringContainsString(
            'Prilike u nacrtu 0 Otvori spisak nacrta Vodiči u nacrtu 0 Otvori spisak nacrta Organizacije u nacrtu 0 Otvori spisak nacrta',
            $this->vidljivTekst($this->pocetna()),
        );
    }

    #[Test]
    public function kartica_prilika_vodi_na_spisak_samo_sa_nacrtima(): void
    {
        Prilika::factory()->create(['naslov' => 'Nacrt prilike za pregled', 'status' => StatusPrilike::Nacrt]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Objavljena prilika na sajtu']);
        Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana prilika iz prošlosti']);

        $adresa = $this->adresaKartice($this->pocetna(), NacrtiZaPregled::PRILIKE);

        $this->assertStringStartsWith(PrilikaResource::getUrl('index'), $adresa);
        $this->get($adresa)->assertOk()
            ->assertSee('Nacrt prilike za pregled')
            ->assertDontSee('Objavljena prilika na sajtu')
            ->assertDontSee('Arhivirana prilika iz prošlosti');

        // Ogledalo: isti spisak bez filtera pokazuje sve tri.
        $this->get(PrilikaResource::getUrl('index'))->assertOk()
            ->assertSee('Nacrt prilike za pregled')
            ->assertSee('Objavljena prilika na sajtu')
            ->assertSee('Arhivirana prilika iz prošlosti');
    }

    #[Test]
    public function kartica_vodica_vodi_na_spisak_samo_sa_nacrtima(): void
    {
        Vodic::factory()->create(['naslov' => 'Nacrt vodiča za pregled']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Objavljen vodič na sajtu']);

        $adresa = $this->adresaKartice($this->pocetna(), NacrtiZaPregled::VODICI);

        $this->assertStringStartsWith(VodicResource::getUrl('index'), $adresa);
        $this->get($adresa)->assertOk()
            ->assertSee('Nacrt vodiča za pregled')
            ->assertDontSee('Objavljen vodič na sajtu');

        $this->get(VodicResource::getUrl('index'))->assertOk()
            ->assertSee('Nacrt vodiča za pregled')
            ->assertSee('Objavljen vodič na sajtu');
    }

    #[Test]
    public function kartica_organizacija_vodi_na_spisak_samo_sa_nacrtima(): void
    {
        Organizacija::factory()->create(['naziv' => 'Nacrt organizacije za pregled']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Objavljena organizacija na sajtu']);

        $adresa = $this->adresaKartice($this->pocetna(), NacrtiZaPregled::ORGANIZACIJE);

        $this->assertStringStartsWith(OrganizacijaResource::getUrl('index'), $adresa);
        $this->get($adresa)->assertOk()
            ->assertSee('Nacrt organizacije za pregled')
            ->assertDontSee('Objavljena organizacija na sajtu');

        $this->get(OrganizacijaResource::getUrl('index'))->assertOk()
            ->assertSee('Nacrt organizacije za pregled')
            ->assertSee('Objavljena organizacija na sajtu');
    }

    // Tri različite adrese: kartica ne sme da vodi na tuđ spisak.
    #[Test]
    public function svaka_kartica_vodi_na_svoj_spisak(): void
    {
        $html = $this->pocetna();
        $adrese = [
            $this->adresaKartice($html, NacrtiZaPregled::PRILIKE),
            $this->adresaKartice($html, NacrtiZaPregled::VODICI),
            $this->adresaKartice($html, NacrtiZaPregled::ORGANIZACIJE),
        ];

        $this->assertCount(3, array_unique($adrese));
        $this->assertStringStartsWith(PrilikaResource::getUrl('index'), $adrese[0]);
        $this->assertStringStartsWith(VodicResource::getUrl('index'), $adrese[1]);
        $this->assertStringStartsWith(OrganizacijaResource::getUrl('index'), $adrese[2]);
    }

    // Brojevi su isti kao ono što spisak posle klika zaista pokazuje.
    #[Test]
    public function broj_na_kartici_je_broj_kartica_na_spisku_posle_klika(): void
    {
        $this->napraviSadrzaj();
        $html = $this->pocetna();

        foreach ([[NacrtiZaPregled::PRILIKE, 2], [NacrtiZaPregled::VODICI, 3], [NacrtiZaPregled::ORGANIZACIJE, 1]] as [$natpis, $broj]) {
            $spisak = (string) $this->get($this->adresaKartice($html, $natpis))->assertOk()->getContent();

            // Kartice spiska nose ključ zapisa; „listitem“ nosi i oznaka aktivnog filtera.
            $this->assertSame($broj, substr_count($spisak, '.table.records.'), $natpis);
        }
    }

    // Brojevi se ne osvežavaju svakih pet sekundi (Filamentovo podrazumevano ponašanje bi slalo upit za svaku otvorenu stranu).
    #[Test]
    public function kartice_se_ne_osvezavaju_same(): void
    {
        $this->assertStringNotContainsString('wire:poll', Livewire::test(NacrtiZaPregled::class)->html());
    }
}
