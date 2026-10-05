<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class JavniSpisakPrilikaTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{0: string}> */
    public static function adreseSpiska(): array
    {
        return ['/prilike' => ['prilike.index'], 'početna /' => ['pocetna']];
    }

    #[Test]
    #[DataProvider('adreseSpiska')]
    public function javna_prilika_se_vidi_a_nacrt_arhivirana_i_istekla_ne(string $ruta): void
    {
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 20))->create(['naslov' => 'Javna prilika']);
        Prilika::factory()->create(['naslov' => 'Nacrt prilika']);
        Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana prilika']);
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 4))->create(['naslov' => 'Istekla prilika']);

        $tekst = $this->vidljivTekst($this->get(route($ruta))->assertOk()->getContent());

        $this->assertStringContainsString('Javna prilika', $tekst);
        $this->assertStringNotContainsString('Nacrt prilika', $tekst);
        $this->assertStringNotContainsString('Arhivirana prilika', $tekst);
        $this->assertStringNotContainsString('Istekla prilika', $tekst);
    }

    // Ogledalo: rok od danas je još javan, a sutradan više nije.
    #[Test]
    public function rok_danas_je_javan_a_sutradan_vise_nije(): void
    {
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 5))->create(['naslov' => 'Rok danas']);

        $this->assertStringContainsString('Rok danas', $this->vidljivTekst($this->get(route('prilike.index'))->getContent()));

        $this->travelTo(Carbon::create(2026, 10, 6, 0, 0, 0, 'Europe/Belgrade'));

        $this->assertStringNotContainsString('Rok danas', $this->vidljivTekst($this->get(route('prilike.index'))->getContent()));
    }

    #[Test]
    public function najblizi_rok_je_prvi_a_bez_roka_i_stalno_otvorene_su_na_kraju(): void
    {
        // Pravljene suprotnim redom od očekivanog, da poredak ne bi bio samo redosled upisa.
        Prilika::factory()->objavljena()->stalnoOtvorena()->saRokom(Carbon::create(2026, 10, 6))->create(['naslov' => 'Peta, stalno otvorena']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Četvrta, bez roka', 'rok' => null]);
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 12, 1))->create(['naslov' => 'Treća, decembar']);
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 20))->create(['naslov' => 'Druga, 20. oktobar']);
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 5))->create(['naslov' => 'Prva, danas']);

        $this->get(route('prilike.index'))->assertSeeInOrder([
            'Prva, danas', 'Druga, 20. oktobar', 'Treća, decembar', 'Četvrta, bez roka', 'Peta, stalno otvorena',
        ]);
    }

    #[Test]
    public function prazan_spisak_kaze_da_nema_otvorenih_prilika(): void
    {
        Prilika::factory()->create(['naslov' => 'Samo nacrt']);

        $this->assertStringContainsString('Trenutno nema otvorenih prilika.', $this->vidljivTekst($this->get(route('prilike.index'))->getContent()));
    }

    // Ogledalo: kad ima javnih prilika, ta rečenica se ne piše.
    #[Test]
    public function spisak_sa_prilikom_ne_kaze_da_nema_otvorenih_prilika(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Javna']);

        $this->assertStringNotContainsString('Trenutno nema otvorenih prilika.', $this->vidljivTekst($this->get(route('prilike.index'))->getContent()));
    }

    #[Test]
    public function kartica_nosi_naslov_vrstu_rok_mesto_i_kratak_opis(): void
    {
        Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 20))->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => 'posao', 'mesto' => 'Niš', 'online' => false, 'kratak_opis' => 'Puno radno vreme.',
        ]);

        $tekst = $this->vidljivTekst($this->get(route('prilike.index'))->getContent());

        $this->assertStringContainsString('Radnik u skladištu Posao Rok: 20.10.2026. Niš Puno radno vreme.', $tekst);
        $this->assertStringNotContainsString('2026-10-20', $tekst);
        $this->assertStringNotContainsString('Online', $tekst);
    }

    #[Test]
    public function online_prilika_pise_online_a_ne_mesto(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Rad od kuće', 'mesto' => 'Beograd', 'online' => true]);

        $tekst = $this->vidljivTekst($this->get(route('prilike.index'))->getContent());

        $this->assertStringContainsString('Online', $tekst);
        $this->assertStringNotContainsString('Beograd', $tekst);
    }

    // Ogledalo: prazna vrednost ne crta red, a rok se piše po tri pravila iz paketa.
    #[Test]
    public function bez_mesta_nema_reda_za_mesto_a_rok_ima_tri_oblika(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'A bez roka', 'rok' => null, 'mesto' => null, 'online' => false, 'kratak_opis' => 'Opis A.']);
        Prilika::factory()->objavljena()->stalnoOtvorena()->saRokom(Carbon::create(2026, 10, 6))->create(['naslov' => 'B stalno', 'mesto' => '', 'online' => false, 'kratak_opis' => 'Opis B.']);

        $tekst = $this->vidljivTekst($this->get(route('prilike.index'))->getContent());

        $this->assertStringContainsString('Bez roka Opis A.', $tekst);
        $this->assertStringContainsString('Prijave stalno otvorene Opis B.', $tekst);
        $this->assertStringNotContainsString('Rok:', $tekst);
    }

    #[Test]
    public function naslov_i_opis_se_ne_izvrsavaju_kao_html(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => '<script>alert(1)</script>', 'kratak_opis' => '<b>Podebljano</b>']);

        $html = $this->get(route('prilike.index'))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Podebljano</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
