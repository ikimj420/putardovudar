<?php

namespace Tests\Feature\Baza;

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

// Paket 35: oznake. Svaka javna strana ima tačno jedan naslov prvog nivoa, polja imaju oznaku, dugmad i grupe veza imaju naziv,
// a nijedna veza nije prazna.
#[Group('baza')]
class PristupacnostMarkeraTest extends BazaTestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function adrese(): array
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => 'posao']);
        $vodic = Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV']);
        $organizacija = Organizacija::factory()->objavljena()->create(['naziv' => 'Fondacija Primer', 'sajt' => 'https://primer.rs/']);

        return [
            'početna' => route('pocetna'), 'prilike' => route('prilike.index'), 'prilike, filter' => route('prilike.index', ['vrsta' => 'posao']), 'prilika' => route('prilike.show', $prilika->slug),
            'vodiči' => route('vodici.index'), 'vodič' => route('vodici.show', $vodic->slug), 'organizacije' => route('organizacije.index'),
            'organizacija' => route('organizacije.show', $organizacija->slug), 'pomoćnik' => route('pomocnik.index'),
            'ne postoji (prilika)' => route('prilike.show', 'nema-je'), 'ne postoji (vodič)' => route('vodici.show', 'nema-ga'), 'ne postoji (organizacija)' => route('organizacije.show', 'nema-je'),
        ];
    }

    #[Test]
    public function svaka_javna_strana_ima_tacno_jedan_naslov_prvog_nivoa_pa_i_ona_koja_ne_postoji(): void
    {
        $adrese = $this->adrese();

        foreach ($adrese as $ime => $adresa) {
            $odgovor = $this->get($adresa);
            $this->assertSame(1, substr_count($odgovor->getContent(), '<h1'), $ime);
            $this->assertSame(1, preg_match('#<h1[^>]*>\s*\S#', $odgovor->getContent()), $ime);
        }

        $this->assertCount(12, $adrese);
    }

    // Strana „ne postoji" nosi poruku kao naslov, sa istim tekstom kao pre.
    #[Test]
    public function strana_ne_postoji_ima_poruku_kao_naslov(): void
    {
        $this->assertMatchesRegularExpression('#<h1 class="prazno">\s*'.preg_quote(PrikazPrilike::NEMA_STRANE, '#').'\s*</h1>#', $this->get(route('prilike.show', 'nema-je'))->getContent());
        $this->assertMatchesRegularExpression('#<h1 class="prazno">\s*'.preg_quote(PrikazVodica::NEMA_STRANE, '#').'\s*</h1>#', $this->get(route('vodici.show', 'nema-ga'))->getContent());
        $this->assertMatchesRegularExpression('#<h1 class="prazno">\s*'.preg_quote(PrikazOrganizacija::NEMA_STRANE, '#').'\s*</h1>#', $this->get(route('organizacije.show', 'nema-je'))->getContent());
    }

    #[Test]
    public function polje_za_pitanje_ima_oznaku_a_dugme_ima_tekst(): void
    {
        $html = $this->get(route('pomocnik.index'))->getContent();

        $this->assertMatchesRegularExpression('#<label for="pitanje">[^<]+</label>#', $html);
        $this->assertStringContainsString('<textarea id="pitanje" name="pitanje"', $html);
        $this->assertMatchesRegularExpression('#<button type="submit">[^<]+</button>#', $html);
    }

    // Grupe veza (glavni meni, putanja, filter) nose različite nazive, da ih čitač ekrana razlikuje.
    #[Test]
    public function grupe_veza_na_strani_imaju_razlicite_nazive(): void
    {
        foreach ($this->adrese() as $ime => $adresa) {
            $html = $this->get($adresa)->getContent();
            preg_match_all('#<nav\b[^>]*aria-label="([^"]+)"#', $html, $nazivi);

            $this->assertSame(substr_count($html, '<nav'), count($nazivi[1]), $ime.': svaka grupa veza ima naziv');
            $this->assertSame($nazivi[1], array_values(array_unique($nazivi[1])), $ime.': nazivi su različiti');
        }

        $html = $this->get(route('prilike.index'))->getContent();
        preg_match_all('#<nav\b[^>]*aria-label="([^"]+)"#', $html, $nazivi);
        $this->assertSame(['Glavni meni', 'Putanja', 'Brzi filteri'], $nazivi[1]);
    }

    #[Test]
    public function nijedna_veza_i_nijedno_dugme_nisu_prazni(): void
    {
        $proverenih = 0;

        foreach ($this->adrese() as $ime => $adresa) {
            $html = $this->get($adresa)->getContent();
            preg_match_all('#<(a|button)\b([^>]*)>(.*?)</\1>#s', $html, $nalazi, PREG_SET_ORDER);

            foreach ($nalazi as $nalaz) {
                $proverenih++;
                $tekst = trim((string) preg_replace('/\s+/', ' ', strip_tags($nalaz[3])));
                $this->assertTrue($tekst !== '' || str_contains($nalaz[2], 'aria-label='), $ime.': '.$nalaz[0]);
            }
        }

        $this->assertGreaterThan(40, $proverenih);
    }

    // Ogledalo: dugme menija ima samo ikonu, pa mu naziv mora da stoji u oznaci.
    #[Test]
    public function dugme_menija_sa_ikonom_ima_naziv_u_oznaci(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();

        preg_match('#<button class="dugme-meni"([^>]*)>(.*?)</button>#s', $html, $dugme);
        $this->assertSame('☰', trim($dugme[2]));
        $this->assertStringContainsString('aria-label="Otvori meni"', $dugme[1]);
    }

    #[Test]
    public function jezik_i_naslov_stranice_su_postavljeni(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu']);

        $html = $this->get(route('prilike.show', $prilika->slug))->getContent();

        $this->assertMatchesRegularExpression('#<html lang="[a-z]{2}(?:-[A-Za-z]+)?">#', $html);
        $this->assertStringContainsString('<title>Radnik u skladištu - ', $html);
    }
}
