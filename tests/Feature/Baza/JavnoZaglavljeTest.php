<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class JavnoZaglavljeTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function zaglavlje_na_praznom_spisku_spisku_i_strani_prilike_nosi_ime_sajta_i_link_prilike(): void
    {
        $adrese = [route('prilike.index')];
        $this->assertStringContainsString('Trenutno nema otvorenih prilika.', $this->get($adrese[0])->getContent());

        $prilika = Prilika::factory()->objavljena()->create();
        $adrese[] = route('pocetna');
        $adrese[] = route('prilike.show', $prilika->slug);

        foreach ($adrese as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<header class="zaglavlje">.*?<a class="logo" href="'.preg_quote(route('pocetna'), '#').'">Putardo Vudar</a>.*?<a href="'.preg_quote(route('prilike.index'), '#').'">Prilike</a>.*?</header>#s', $html, $adresa);
        }
    }

    // Ogledalo: zaglavlje je samo ime sajta i jedan link, bez ostalih stavki menija.
    #[Test]
    public function zaglavlje_nema_drugih_linkova(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();
        preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);

        $this->assertSame(2, preg_match_all('#<a\b#', $zaglavlje[1] ?? ''));
    }

    #[Test]
    public function stil_je_obican_css_fajl_koji_postoji_a_ne_build(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();

        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/css/javno\.css\?v=\d+">#', $html);
        $this->assertFileExists(public_path('css/javno.css'));
        $this->assertStringNotContainsString('/build/', $html);
    }
}
