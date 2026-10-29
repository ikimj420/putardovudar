<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazZaglavlja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 80/T1 (Ivan, 29.10.2026.): „Stipendije" je u meniju posle „Prilike" i vodi na spisak prilika sa izabranom vrstom; oznaku „ovde si" nosi samo kad je ta vrsta izabrana.
#[Group('baza')]
class MeniStipendijeTest extends BazaTestCase
{
    use RefreshDatabase;

    private function stipendija(): Prilika
    {
        return Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Stipendija, 'naslov' => 'Stipendija za studente']);
    }

    /** @return array<string, string> naziv veze u meniju => oznaka aria-current ('' kad je nema) */
    private function meni(string $adresa): array
    {
        $html = $this->get($adresa)->getContent();
        preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
        preg_match_all('#<div class="meni-sredina">(.*?)</div>#s', $zaglavlje[1] ?? '', $sredina);
        preg_match_all('#<a\b([^>]*)>(.*?)</a>#s', $sredina[1][0] ?? '', $veze, PREG_SET_ORDER);

        return array_combine(
            array_map(fn (array $veza) => trim(strip_tags($veza[2])), $veze),
            array_map(fn (array $veza) => str_contains($veza[1], 'aria-current="page"') ? 'tekuca' : '', $veze),
        );
    }

    #[Test]
    public function stipendije_su_u_meniju_odmah_posle_prilika_i_vode_na_spisak_te_vrste(): void
    {
        $this->stipendija();

        $this->assertSame(['Prilike', 'Stipendije', 'Vodiči', 'Organizacije', 'Kalendar'], array_keys($this->meni(route('prilike.index'))));
        $this->get(route('prilike.index'))->assertSee('href="'.route('prilike.index', ['vrsta' => 'stipendija']).'"', false);
        $this->assertSame('Stipendije', PrikazZaglavlja::STIPENDIJE);
    }

    /** @return array<string, array{0: string}> ime rute => ništa; adrese se prave u testu */
    public static function strane(): array
    {
        return ['početna' => ['pocetna'], 'prilike' => ['prilike.index'], 'vodiči' => ['vodici.index'], 'organizacije' => ['organizacije.index'], 'kalendar' => ['kalendar.index'], 'pomoćnik' => ['pomocnik.index']];
    }

    #[Test]
    #[DataProvider('strane')]
    public function stipendije_su_u_meniju_na_svakoj_javnoj_strani(string $ruta): void
    {
        $this->assertContains('Stipendije', array_keys($this->meni(route($ruta))), $ruta);
    }

    // Kad je izabrana vrsta „stipendija" (i ima je), oznaku ima „Stipendije", a „Prilike" je nemaju; na ostalim stranama obrnuto.
    #[Test]
    public function oznaku_ovde_si_ima_tacno_jedna_veza_a_za_stipendije_samo_kad_je_ta_vrsta_izabrana(): void
    {
        $stipendija = $this->stipendija();
        Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Posao]);

        $this->assertSame(['Prilike' => '', 'Stipendije' => 'tekuca', 'Vodiči' => '', 'Organizacije' => '', 'Kalendar' => ''], $this->meni(route('prilike.index', ['vrsta' => 'stipendija'])));
        $this->assertSame(['Prilike' => 'tekuca', 'Stipendije' => '', 'Vodiči' => '', 'Organizacije' => '', 'Kalendar' => ''], $this->meni(route('prilike.index')));
        // Druga vrsta, i strana jedne prilike (čak i stipendije): „Prilike" ostaje tekuća.
        $this->assertSame(['Prilike' => 'tekuca', 'Stipendije' => '', 'Vodiči' => '', 'Organizacije' => '', 'Kalendar' => ''], $this->meni(route('prilike.index', ['vrsta' => 'posao'])));
        $this->assertSame(['Prilike' => 'tekuca', 'Stipendije' => '', 'Vodiči' => '', 'Organizacije' => '', 'Kalendar' => ''], $this->meni(route('prilike.show', $stipendija->slug)));
        // Ogledalo: druge strane imaju svoju tekuću vezu, a „Stipendije" nikad.
        $this->assertSame('tekuca', $this->meni(route('vodici.index'))['Vodiči']);
        $this->assertSame('', $this->meni(route('vodici.index'))['Stipendije']);
    }

    // Izmereno (PITANJA-80.md): bez javne stipendije veza vodi na spisak svih prilika, bez izmene; tada „ovde si" nosi „Prilike", jer je to strana koja se vidi.
    #[Test]
    public function bez_javne_stipendije_veza_daje_sve_prilike_a_tekuce_su_prilike(): void
    {
        Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Posao, 'naslov' => 'Radnik u skladištu']);
        Prilika::factory()->create(['vrsta' => VrstaPrilike::Stipendija, 'naslov' => 'Nacrt stipendije']);

        $this->get(route('prilike.index', ['vrsta' => 'stipendija']))->assertOk()->assertSee('Radnik u skladištu')->assertDontSee('Nacrt stipendije');
        $this->assertSame(['Prilike' => 'tekuca', 'Stipendije' => '', 'Vodiči' => '', 'Organizacije' => '', 'Kalendar' => ''], $this->meni(route('prilike.index', ['vrsta' => 'stipendija'])));

        // Ogledalo: čim ima javna stipendija, spisak je samo ona.
        $this->stipendija();
        $this->get(route('prilike.index', ['vrsta' => 'stipendija']))->assertSee('Stipendija za studente')->assertDontSee('Radnik u skladištu');
    }
}
