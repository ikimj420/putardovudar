<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class JavnaStranaPrilikeTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
    }

    #[Test]
    public function javna_prilika_se_otvara_sa_svim_podacima_i_linkom_izvora(): void
    {
        $prilika = Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 20))->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => 'posao', 'mesto' => 'Niš', 'kratak_opis' => 'Puno radno vreme.',
            'opis' => "Prvi red opisa.\nDrugi red opisa.",
            'naziv_izvora' => 'Nacionalna služba za zapošljavanje', 'link_izvora' => 'https://primer.rs/konkurs',
        ]);

        $odgovor = $this->get(route('prilike.show', $prilika->slug))->assertOk();
        $html = $odgovor->getContent();

        $this->assertStringContainsString('Radnik u skladištu Posao Rok: 20.10.2026. Niš Puno radno vreme. Prvi red opisa.', $this->vidljivTekst($html));
        $this->assertMatchesRegularExpression('#Prvi red opisa\.<br\s*/?>\s*Drugi red opisa\.#', $html);
        $this->assertMatchesRegularExpression('#Zvanični izvor:\s*<a href="https://primer\.rs/konkurs"[^>]*>Nacionalna služba za zapošljavanje</a>#', $html);
        $odgovor->assertSeeInOrder(['Zvanični izvor:', 'Nacionalna služba za zapošljavanje', 'Pre prijave proveri podatke na zvaničnom izvoru.']);
    }

    // Ogledalo: nejavne prilike i nepostojeća adresa daju 404, ne 403, a javna sa istim kalupom daje 200.
    #[Test]
    public function nacrt_arhivirana_istekla_i_nepostojeca_vracaju_404_a_ne_403(): void
    {
        $nacrt = Prilika::factory()->create();
        $arhivirana = Prilika::factory()->arhivirana()->create();
        $istekla = Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 4))->create();
        $javna = Prilika::factory()->objavljena()->create();

        foreach ([$nacrt->slug, $arhivirana->slug, $istekla->slug, 'nema-takve-prilike'] as $slug) {
            $this->get(route('prilike.show', $slug))
                ->assertStatus(404)
                ->assertSee('Ta strana ne postoji ili prilika više nije otvorena.')
                ->assertDontSee('Not Found');
        }

        $this->get(route('prilike.show', $javna->slug))
            ->assertStatus(200)
            ->assertDontSee('Ta strana ne postoji ili prilika više nije otvorena.');
    }

    #[Test]
    public function istekla_sutradan_vise_nije_dostupna(): void
    {
        $prilika = Prilika::factory()->objavljena()->saRokom(Carbon::create(2026, 10, 5))->create();

        $this->get(route('prilike.show', $prilika->slug))->assertOk();

        $this->travelTo(Carbon::create(2026, 10, 6, 0, 0, 0, 'Europe/Belgrade'));

        $this->get(route('prilike.show', $prilika->slug))->assertStatus(404);
    }

    #[Test]
    public function bez_naziva_izvora_link_nosi_adresu_sajta(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naziv_izvora' => null, 'link_izvora' => 'https://www.primer.rs/konkurs/2026']);

        $html = $this->get(route('prilike.show', $prilika->slug))->getContent();

        $this->assertMatchesRegularExpression('#Zvanični izvor:\s*<a href="https://www\.primer\.rs/konkurs/2026"[^>]*>www\.primer\.rs</a>#', $html);
    }

    // Izvor može da stigne u bazu mimo pravila objave (masovni upit): strana ga ne sme pretvoriti u link.
    #[Test]
    public function izvor_koji_nije_veb_adresa_ne_postaje_link(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naziv_izvora' => 'Izvor', 'link_izvora' => 'https://primer.rs']);
        Prilika::query()->whereKey($prilika->id)->update(['link_izvora' => 'javascript:alert(1)']);

        $html = $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Zvanični izvor: Izvor', $this->vidljivTekst($html));
    }

    #[Test]
    public function naslov_i_opis_se_ne_izvrsavaju_kao_html(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => '<script>alert(1)</script>', 'opis' => '<img src=x onerror=alert(1)>']);

        $html = $this->get(route('prilike.show', $prilika->slug))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    #[Test]
    public function naslov_kartice_na_spisku_vodi_na_stranu_prilike(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Jedna prilika']);

        $html = $this->get(route('prilike.index'))->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('prilike.show', $prilika->slug), '#').'">Jedna prilika</a>#', $html);
    }

    #[Test]
    public function naslov_kartice_u_pregledacu_je_naslov_prilike_i_ime_sajta(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Jedna prilika']);

        $this->assertSame('Jedna prilika - Putardo Vudar', $this->naslovStrane($this->get(route('prilike.show', $prilika->slug))->getContent()));
        $this->assertSame('Prilike - Putardo Vudar', $this->naslovStrane($this->get(route('prilike.index'))->getContent()));
    }
}
