<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Organizacija;
use App\Support\PrikazOrganizacija;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class OrganizacijeJavnoTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    #[Test]
    public function spisak_pokazuje_samo_objavljene_organizacije_kao_kartice(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Objavljena organizacija', 'kratak_opis' => 'Kratak opis objavljene.', 'vrsta' => VrstaOrganizacije::Fondacija, 'mesto' => 'Beograd']);
        Organizacija::factory()->create(['naziv' => 'Nacrt organizacija']);

        $html = $this->get(route('organizacije.index'))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Objavljena organizacija', $tekst);
        $this->assertStringContainsString('Fondacija', $tekst);
        $this->assertStringContainsString('Beograd', $tekst);
        $this->assertStringContainsString('Kratak opis objavljene.', $tekst);
        $this->assertStringNotContainsString('Nacrt organizacija', $tekst);
        // Od paketa 33 kartica nosi i klasu redosleda; i dalje je jedna kartica po objavljenom zapisu, a ne red tabele.
        $this->assertSame(1, substr_count($html, '<article class="kartica redosled">'));
        $this->assertStringNotContainsString('<table', $html);
    }

    #[Test]
    public function prazan_spisak_kaze_da_nema_organizacija(): void
    {
        Organizacija::factory()->create();

        $this->assertStringContainsString(PrikazOrganizacija::NEMA_ORGANIZACIJA, $this->vidljivTekst($this->get(route('organizacije.index'))->assertOk()->getContent()));
    }

    #[Test]
    public function strana_organizacije_nosi_podatke_sajt_i_usluge_ali_ne_belesku(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create([
            'naziv' => 'Fondacija Primer', 'vrsta' => VrstaOrganizacije::Fondacija, 'kratak_opis' => 'Kratko.', 'opis' => "Prvi red.\nDrugi red.",
            'mesto' => 'Terazije 39, Beograd', 'online' => true, 'telefon' => '011/123-456', 'sajt' => 'https://www.primer.rs/kontakt',
            'usluge' => ['Savetovanje', 'Konkursi'], 'beleska' => 'Tajna beleška za admina.',
        ]);

        $html = $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Fondacija Primer', $tekst);
        $this->assertStringContainsString('Mesto: Terazije 39, Beograd', $tekst);
        $this->assertStringContainsString('Online', $tekst);
        $this->assertStringContainsString('Telefon: 011/123-456', $tekst);
        $this->assertStringContainsString("Prvi red.<br />\nDrugi red.", $html);
        $this->assertStringContainsString('<a href="https://www.primer.rs/kontakt" rel="noopener noreferrer nofollow" target="_blank">www.primer.rs</a>', $html);
        $this->assertStringContainsString('<ul class="usluge">', $html);
        $this->assertStringContainsString('<li>Savetovanje</li>', $html);
        $this->assertStringNotContainsString('Tajna beleška', $html);
        $this->assertSame('Fondacija Primer - Putardo Vudar', $this->naslovStrane($html));
    }

    // Ogledalo: organizacija bez telefona, mesta, usluga i opisa ne crta te redove.
    #[Test]
    public function prazna_polja_ne_crtaju_redove(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create(['opis' => null, 'mesto' => null, 'online' => false, 'telefon' => null, 'usluge' => null]);

        $tekst = $this->vidljivTekst($this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent());

        $this->assertStringNotContainsString(PrikazOrganizacija::MESTO, $tekst);
        $this->assertStringNotContainsString(PrikazOrganizacija::TELEFON, $tekst);
        $this->assertStringNotContainsString(PrikazOrganizacija::USLUGE, $tekst);
        $this->assertStringNotContainsString(PrikazOrganizacija::ONLINE, $tekst);
    }

    #[Test]
    public function nacrt_je_za_posetioca_nepostojeci_a_posle_objave_se_vidi(): void
    {
        $organizacija = Organizacija::factory()->create();

        $this->get(route('organizacije.show', $organizacija->slug))->assertNotFound();
        $this->get(route('organizacije.show', 'nepostojeci-slug'))->assertNotFound();

        $organizacija->update(['status' => StatusObjave::Objavljeno]);

        $this->get(route('organizacije.show', $organizacija->slug))->assertOk();
    }

    #[Test]
    public function sajt_koji_nije_veb_adresa_se_ne_ispisuje_kao_link(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create();
        // Masovni upit zaobilazi pravilo objave; strana ipak ne sme da napravi link od „javascript:".
        Organizacija::query()->whereKey($organizacija->getKey())->update(['sajt' => 'javascript:alert(1)']);

        $html = $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    #[Test]
    public function tekst_se_ispisuje_bez_html_a(): void
    {
        $organizacija = Organizacija::factory()->objavljena()->create(['opis' => '<script>alert(1)</script> Opis.', 'usluge' => ['<b>usluga</b>']]);

        $html = $this->get(route('organizacije.show', $organizacija->slug))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>usluga</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;usluga&lt;/b&gt;', $html);
    }

    #[Test]
    public function spisak_je_po_abecedi_naziva(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Zadnja']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Prva']);

        $tekst = $this->vidljivTekst($this->get(route('organizacije.index'))->getContent());

        $this->assertLessThan(mb_strpos($tekst, 'Zadnja'), mb_strpos($tekst, 'Prva'));
    }

    #[Test]
    public function jedino_mesto_za_javno_je_opseg_javne(): void
    {
        Organizacija::factory()->objavljena()->count(2)->create();
        Organizacija::factory()->count(3)->create();

        $this->assertSame(2, Organizacija::javne()->count());
    }

    /** @return array<string, array{0: string|null}> */
    public static function neispravniSajtovi(): array
    {
        return [
            'null' => [null], 'prazno' => [''], 'razmaci' => ['   '], 'bez šeme' => ['www.primer.rs'], 'ftp' => ['ftp://primer.rs'],
            'javascript' => ['javascript:alert(1)'], 'nije adresa' => ['nije adresa'],
        ];
    }

    #[Test]
    #[DataProvider('neispravniSajtovi')]
    public function objava_bez_ispravnog_linka_sajta_ne_prolazi_a_nacrt_prolazi(?string $sajt): void
    {
        $organizacija = Organizacija::factory()->create(['sajt' => $sajt]);

        $this->assertSame(StatusObjave::Nacrt, $organizacija->fresh()->status);

        try {
            $organizacija->update(['status' => StatusObjave::Objavljeno]);
            $this->fail('Objava bez ispravnog linka sajta je prošla.');
        } catch (ValidationException $izuzetak) {
            $this->assertSame([Organizacija::PORUKA_BEZ_SAJTA], $izuzetak->errors()['sajt']);
        }

        $this->assertSame(StatusObjave::Nacrt, $organizacija->fresh()->status);
        $this->assertSame(0, Organizacija::javne()->count());
    }

    // Ogledalo: isti zapis sa ispravnim linkom se objavljuje, i posle toga mu se sajt ne može obrisati.
    #[Test]
    public function objava_sa_linkom_sajta_prolazi_a_objavljenoj_se_sajt_ne_brise(): void
    {
        $organizacija = Organizacija::factory()->create(['sajt' => 'http://primer.rs']);

        $organizacija->update(['status' => StatusObjave::Objavljeno]);

        $this->assertSame(1, Organizacija::javne()->count());

        $this->expectException(ValidationException::class);

        $organizacija->update(['sajt' => null]);
    }

    #[Test]
    public function slug_se_pravi_iz_naziva_a_nova_organizacija_je_nacrt(): void
    {
        $prva = Organizacija::query()->create(['naziv' => 'Nacionalna služba', 'vrsta' => VrstaOrganizacije::JavnaInstitucija, 'kratak_opis' => 'Opis.']);
        $druga = Organizacija::query()->create(['naziv' => 'Nacionalna služba', 'vrsta' => VrstaOrganizacije::Drugo, 'kratak_opis' => 'Opis.']);

        $this->assertSame('nacionalna-sluzba', $prva->slug);
        $this->assertSame('nacionalna-sluzba-2', $druga->slug);
        $this->assertSame(StatusObjave::Nacrt, $prva->fresh()->status);
        $this->assertFalse($prva->fresh()->online);
    }
}
