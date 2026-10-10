<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\PrikazOrganizacija;
use App\Support\PrikazPrilike;
use App\Support\PrikazVodica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 23, T4: strana „ne postoji" za vodič i organizaciju ne pominje priliku; tekst za prilike ostaje.
#[Group('baza')]
class NepostojecaStranaVodicaIOrganizacijaTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    // Samo telo strane: zaglavlje ima meni sa rečju „Prilike", a ona ne sme da se računa kao tekst o grešci.
    private function tekstStrane(string $adresa): string
    {
        $html = (string) $this->get($adresa)->assertNotFound()->getContent();
        $this->assertSame(1, preg_match('#<main[^>]*>(.*?)</main>#s', $html, $telo), 'strana nema <main>');

        return $this->vidljivTekst($telo[1]);
    }

    #[Test]
    public function nepostojeci_vodic_dobija_svoj_tekst_bez_price_o_prilici(): void
    {
        $tekst = $this->tekstStrane('/vodici/nema-ga');

        $this->assertStringContainsString(PrikazVodica::NEMA_STRANE, $tekst);
        $this->assertStringNotContainsStringIgnoringCase('prilik', $tekst);
        $this->assertStringNotContainsString(PrikazPrilike::NEMA_STRANE, $tekst);
    }

    #[Test]
    public function vodic_u_nacrtu_dobija_isti_tekst_kao_nepostojeci(): void
    {
        Vodic::factory()->create(['slug' => 'u-nacrtu', 'status' => StatusObjave::Nacrt]);

        $this->assertSame($this->tekstStrane('/vodici/nema-ga'), $this->tekstStrane('/vodici/u-nacrtu'));
        $this->assertStringNotContainsStringIgnoringCase('nacrt', $this->tekstStrane('/vodici/u-nacrtu'));
    }

    #[Test]
    public function nepostojeca_organizacija_dobija_svoj_tekst_bez_price_o_prilici(): void
    {
        $tekst = $this->tekstStrane('/organizacije/nema-je');

        $this->assertStringContainsString(PrikazOrganizacija::NEMA_STRANE, $tekst);
        $this->assertStringNotContainsStringIgnoringCase('prilik', $tekst);
        $this->assertStringNotContainsString(PrikazPrilike::NEMA_STRANE, $tekst);
    }

    #[Test]
    public function organizacija_u_nacrtu_dobija_isti_tekst_kao_nepostojeca(): void
    {
        Organizacija::factory()->create(['slug' => 'u-nacrtu', 'status' => StatusObjave::Nacrt]);

        $this->assertSame($this->tekstStrane('/organizacije/nema-je'), $this->tekstStrane('/organizacije/u-nacrtu'));
        $this->assertStringNotContainsStringIgnoringCase('nacrt', $this->tekstStrane('/organizacije/u-nacrtu'));
    }

    // Ogledalo: za prilike i za adrese bez strane tekst o prilici ostaje, a nov se ne pojavljuje.
    #[Test]
    public function za_prilike_i_nepoznate_adrese_tekst_o_prilici_ostaje(): void
    {
        Prilika::factory()->create(['slug' => 'u-nacrtu', 'status' => 'nacrt']);

        foreach (['/prilike/nema-je', '/prilike/u-nacrtu', '/nema-ove-strane'] as $adresa) {
            $tekst = $this->tekstStrane($adresa);

            $this->assertStringContainsString(PrikazPrilike::NEMA_STRANE, $tekst, $adresa);
            $this->assertStringNotContainsString(PrikazVodica::NEMA_STRANE, $tekst, $adresa);
            $this->assertStringNotContainsString(PrikazOrganizacija::NEMA_STRANE, $tekst, $adresa);
        }
    }

    // Ogledalo: postojeći objavljeni vodič i organizacija se otvaraju i ne nose ni jedan tekst o grešci.
    #[Test]
    public function objavljeni_vodic_i_organizacija_se_otvaraju(): void
    {
        Vodic::factory()->objavljen()->create(['slug' => 'objavljen']);
        Organizacija::factory()->create(['slug' => 'objavljena', 'status' => StatusObjave::Objavljeno, 'sajt' => 'https://primer.rs']);

        $this->get('/vodici/objavljen')->assertOk();
        $this->get('/organizacije/objavljena')->assertOk();
    }
}
