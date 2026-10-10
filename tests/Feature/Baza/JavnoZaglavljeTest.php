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
    public function zaglavlje_na_praznom_spisku_spisku_i_strani_prilike_nosi_ime_sajta_i_meni_prilike_vodici_organizacije_pomocnik(): void
    {
        $adrese = [route('prilike.index')];
        $this->assertStringContainsString('Trenutno nema otvorenih prilika.', $this->get($adrese[0])->getContent());

        $prilika = Prilika::factory()->objavljena()->create();
        $adrese[] = route('pocetna');
        $adrese[] = route('prilike.show', $prilika->slug);

        foreach ($adrese as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<header class="zaglavlje">.*?<a class="logo" href="'.preg_quote(route('pocetna'), '#').'">Putardo Vudar</a>.*?<a href="'.preg_quote(route('prilike.index'), '#').'">Prilike</a>\s*<a href="'.preg_quote(route('vodici.index'), '#').'">Vodiči</a>\s*<a href="'.preg_quote(route('organizacije.index'), '#').'">Organizacije</a>\s*<a href="'.preg_quote(route('pomocnik.index'), '#').'">Pomoćnik</a>.*?</header>#s', $html, $adresa);
        }
    }

    // Ogledalo: zaglavlje je ime sajta i tačno ovaj meni, bez drugih stavki. Do paketa 18 bio je jedan link (Prilike);
    // Ivanova odluka 10.10.2026.: meni Prilike, Vodiči, Pomoćnik, a Organizacije posle paketa 20.
    #[Test]
    public function zaglavlje_nema_drugih_linkova_osim_menija(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();
        preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
        preg_match_all('#<a\b[^>]*href="([^"]*)"#', $zaglavlje[1] ?? '', $adrese);

        $this->assertSame(
            [route('pocetna'), route('prilike.index'), route('vodici.index'), route('organizacije.index'), route('pomocnik.index')],
            $adrese[1],
        );
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
