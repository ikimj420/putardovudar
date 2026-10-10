<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\PrikazOrganizacija;
use App\Support\PrikazPomocnika;
use App\Support\PrikazVodica;
use App\Support\PrikazZaglavlja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 33: strane po prototipu. Tekstovi su isti kao ranije (to proveravaju postojeći testovi); ovde je struktura:
// naslovna traka sa putanjom i naslovom, kartice u kakvom je redosledu u kodu, bočna kartica izvora.
#[Group('baza')]
class IzgledStranaPoPrototipuTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    /** @return array<string, string> ime strane => adresa, uz po jedan zapis svake vrste */
    private function adrese(): array
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'opis' => 'Dugačak opis.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste']);
        $vodic = Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV', 'koraci' => ['Prvi korak.']]);
        $organizacija = Organizacija::factory()->objavljena()->create(['naziv' => 'Fondacija Primer', 'sajt' => 'https://primer.rs/', 'usluge' => ['Savetovanje']]);

        return [
            'početna' => route('pocetna'), 'prilike' => route('prilike.index'), 'prilika' => route('prilike.show', $prilika->slug),
            'vodiči' => route('vodici.index'), 'vodič' => route('vodici.show', $vodic->slug),
            'organizacije' => route('organizacije.index'), 'organizacija' => route('organizacije.show', $organizacija->slug),
            'pomoćnik' => route('pomocnik.index'),
        ];
    }

    #[Test]
    public function svaka_javna_strana_ima_naslovnu_traku_sa_putanjom_i_jednim_naslovom(): void
    {
        $adrese = $this->adrese();

        foreach ($adrese as $ime => $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertSame(1, preg_match('#<section class="naslovna-traka">(.*?)</section>\s*<div class="sirina sadrzaj">#s', $html, $traka), $ime);
            $this->assertSame(1, substr_count($traka[1], '<h1>'), $ime);
            $this->assertSame(1, substr_count($html, '<h1>'), $ime);
            $this->assertStringContainsString('<nav class="mrvice" aria-label="'.PrikazZaglavlja::PUTANJA.'">', $traka[1], $ime);
            // Putanja počinje imenom sajta koje vodi na početnu.
            $this->assertMatchesRegularExpression('#<nav class="mrvice"[^>]*>\s*<a href="'.preg_quote(route('pocetna'), '#').'">Putardo Vudar</a>#', $traka[1], $ime);
        }

        $this->assertCount(8, $adrese);
    }

    // Ogledalo: strana „ne postoji" nema naslovnu traku, jer nema naslov ni putanju.
    #[Test]
    public function strana_ne_postoji_nema_naslovnu_traku(): void
    {
        $html = $this->get(route('prilike.show', 'nema-je'))->assertNotFound()->getContent();

        $this->assertStringNotContainsString('naslovna-traka', $html);
        $this->assertStringNotContainsString('class="mrvice"', $html);
    }

    #[Test]
    public function putanja_vodi_do_odeljka_a_poslednji_deo_je_naslov_bez_veze(): void
    {
        $adrese = $this->adrese();
        $pocetna = route('pocetna');
        // Za svaku stranu: tekst putanje i veze koje u njoj postoje (ime sajta i odeljak); poslednji deo je običan tekst.
        $ocekivano = [
            'prilike' => ['Putardo Vudar / Prilike', [$pocetna]],
            'prilika' => ['Putardo Vudar / Prilike / Radnik u skladištu', [$pocetna, route('prilike.index')]],
            'vodiči' => ['Putardo Vudar / '.PrikazVodica::NASLOV, [$pocetna]],
            'vodič' => ['Putardo Vudar / '.PrikazVodica::NASLOV.' / Kako napisati prvi CV', [$pocetna, route('vodici.index')]],
            'organizacije' => ['Putardo Vudar / '.PrikazOrganizacija::NASLOV, [$pocetna]],
            'organizacija' => ['Putardo Vudar / '.PrikazOrganizacija::NASLOV.' / Fondacija Primer', [$pocetna, route('organizacije.index')]],
            'pomoćnik' => ['Putardo Vudar / '.PrikazPomocnika::NASLOV, [$pocetna]],
        ];

        foreach ($ocekivano as $ime => [$tekst, $veze]) {
            preg_match('#<nav class="mrvice"[^>]*>(.*?)</nav>#s', $this->get($adrese[$ime])->getContent(), $putanja);
            preg_match_all('#<a href="([^"]+)">#', $putanja[1], $nadjeno);

            $this->assertSame($tekst, trim((string) preg_replace('/\s+/', ' ', strip_tags($putanja[1]))), $ime);
            $this->assertSame($veze, $nadjeno[1], $ime);
        }
    }

    // Redosled u kodu kartice je isti kao pre prototipa (naslov, vrsta, rok, mesto, opis); prototipski redosled slaže stil.
    #[Test]
    public function kartice_zadrzavaju_redosled_u_kodu_a_stil_ih_slaze_kao_u_prototipu(): void
    {
        // Rok od 15.12.2026. mora da ostane budući, inače prilika nestaje sa spiska.
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));

        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'mesto' => 'Niš', 'kratak_opis' => 'Puno radno vreme.', 'rok' => '2026-12-15']);
        $html = $this->get(route('prilike.index'))->getContent();

        $this->assertMatchesRegularExpression('#<article class="kartica redosled">\s*<h2><a [^>]*>Radnik u skladištu</a></h2>\s*<div class="oznake"><span class="oznaka">[^<]+</span></div>\s*<div class="meta">\s*<span class="meta-stavka">Rok: 15\.12\.2026\.</span>\s*<span class="meta-stavka">Niš</span>\s*</div>\s*<p class="kratko">Puno radno vreme\.</p>#s', $html);

        $stil = $this->stilBezUpita();

        foreach (['.redosled .oznake' => 1, '.redosled h2' => 2, '.redosled .kratko' => 3, '.redosled .meta' => 4] as $izbor => $redosled) {
            $this->assertMatchesRegularExpression('/'.preg_quote($izbor, '/').' \{\s*order: '.$redosled.';/', $stil, $izbor);
        }
    }

    #[Test]
    public function spisak_vodica_je_u_tri_kolone_a_prilika_i_organizacija_u_dve(): void
    {
        $adrese = $this->adrese();

        $this->assertStringContainsString('<div class="spisak tri">', $this->get($adrese['vodiči'])->getContent());

        foreach (['prilike', 'organizacije'] as $ime) {
            $html = $this->get($adrese[$ime])->getContent();

            $this->assertStringContainsString('<div class="spisak">', $html, $ime);
            $this->assertStringNotContainsString('spisak tri', $html, $ime);
        }
    }

    // Strana prilike: bočna kolona sa izvorom samo kad ima i opis i izvor; inače jedna kolona.
    #[Test]
    public function bocna_kartica_postoji_samo_uz_opis_i_izvor(): void
    {
        // Objava traži izvor, pa „bez izvora" ovde znači bez opisa: izvor postoji, a glavne kartice sa opisom nema.
        $sve = Prilika::factory()->objavljena()->create(['opis' => 'Opis.', 'naziv_izvora' => 'Izvor', 'link_izvora' => 'https://primer.rs/a']);
        $bezOpisa = Prilika::factory()->objavljena()->create(['opis' => null, 'naziv_izvora' => 'Izvor', 'link_izvora' => 'https://primer.rs/b']);

        $this->assertStringContainsString('<div class="detalj sa-bocnim">', $this->get(route('prilike.show', $sve->slug))->getContent());
        $this->assertStringContainsString('<div class="detalj">', $this->get(route('prilike.show', $bezOpisa->slug))->getContent());
        $this->assertStringNotContainsString('sa-bocnim', $this->get(route('prilike.show', $bezOpisa->slug))->getContent());
        // Izvor stoji u kartici sa upozorenjem, a opis u kartici sa opisom, samo kad ga ima.
        $this->assertStringContainsString('class="izvor-upozorenje"', $this->get(route('prilike.show', $sve->slug))->getContent());
        $this->assertStringContainsString('class="sadrzaj-opis"', $this->get(route('prilike.show', $sve->slug))->getContent());
        $this->assertStringNotContainsString('class="sadrzaj-opis"', $this->get(route('prilike.show', $bezOpisa->slug))->getContent());

        // Druga polovina uslova: opis bez izvora (masovni upit zaobilazi pravilo objave) takođe nema bočnu kolonu.
        $bezIzvora = Prilika::factory()->objavljena()->create(['opis' => 'Opis.', 'naziv_izvora' => 'Izvor', 'link_izvora' => 'https://primer.rs/c']);
        Prilika::query()->whereKey($bezIzvora->getKey())->update(['naziv_izvora' => null, 'link_izvora' => null]);

        $this->assertStringContainsString('<div class="detalj">', $this->get(route('prilike.show', $bezIzvora->slug))->getContent());
        $this->assertStringNotContainsString('sa-bocnim', $this->get(route('prilike.show', $bezIzvora->slug))->getContent());
    }

    // Strana organizacije: bočna kolona sa sajtom samo kad je sajt ispravna adresa; inače jedna kolona.
    #[Test]
    public function bocna_kolona_organizacije_postoji_samo_uz_ispravan_sajt(): void
    {
        $saSajtom = Organizacija::factory()->objavljena()->create(['sajt' => 'https://primer.rs/']);
        $bezSajta = Organizacija::factory()->objavljena()->create();
        Organizacija::query()->whereKey($bezSajta->getKey())->update(['sajt' => 'javascript:alert(1)']);

        $this->assertStringContainsString('<div class="detalj sa-bocnim">', $this->get(route('organizacije.show', $saSajtom->slug))->getContent());
        $this->assertStringContainsString('<div class="detalj">', $this->get(route('organizacije.show', $bezSajta->slug))->getContent());
        $this->assertStringNotContainsString('sa-bocnim', $this->get(route('organizacije.show', $bezSajta->slug))->getContent());
    }

    // Nacrt vodiča i organizacije nema ni strane ni naslovne trake; spisak ih ne pokazuje.
    #[Test]
    public function nacrt_ne_ulazi_ni_u_spisak_novog_izgleda(): void
    {
        Vodic::factory()->create(['naslov' => 'Nacrt vodič', 'status' => StatusObjave::Nacrt]);
        Organizacija::factory()->create(['naziv' => 'Nacrt organizacija', 'status' => StatusObjave::Nacrt]);

        $this->assertStringNotContainsString('Nacrt vodič', $this->get(route('vodici.index'))->getContent());
        $this->assertStringNotContainsString('Nacrt organizacija', $this->get(route('organizacije.index'))->getContent());
    }

    // Prototipske vrednosti izgleda su zaključane u stilu: tesan razmak slova i debljina naslova, pilule, tamno dugme.
    #[Test]
    public function stil_nosi_prototipske_vrednosti_naslova_pilula_i_dugmeta(): void
    {
        $stil = $this->stilBezUpita();

        $this->assertMatchesRegularExpression('/\.naslovna-traka h1 \{[^}]*letter-spacing: -0\.07em;[^}]*font-weight: 950;/s', $stil);
        $this->assertMatchesRegularExpression('/\.kartica h2,\s*\.kartica h3 \{[^}]*letter-spacing: -0\.06em;[^}]*font-weight: 950;/s', $stil);
        $this->assertMatchesRegularExpression('/\.meta-stavka \{[^}]*border-radius: 999px;/s', $stil);
        $this->assertMatchesRegularExpression('/\.oznaka \{[^}]*border-radius: 999px;[^}]*background: var\(--input-bg\);/s', $stil);
        $this->assertMatchesRegularExpression('/\.pitanje button \{[^}]*background: var\(--button-dark-bg\);[^}]*color: var\(--button-dark-text\);/s', $stil);
        $this->assertMatchesRegularExpression('/\.naslovna-traka \{[^}]*radial-gradient\(circle at 72% 24%/s', $stil);
    }
}
