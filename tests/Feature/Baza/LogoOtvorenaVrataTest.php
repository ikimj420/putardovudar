<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use App\Support\PrikazZaglavlja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57 T3: znak „otvorena vrata" uz ime sajta i „Otvorena vrata"; okvir vrata prati boju slova zaglavlja (beo na vrhu, tamno posle skrolovanja).
#[Group('baza')]
class LogoOtvorenaVrataTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    #[Test]
    public function logo_je_jedna_veza_na_pocetnu_sa_znakom_imenom_i_sloganom_na_svakoj_strani(): void
    {
        $prilika = Prilika::factory()->objavljena()->create();
        $obidjeno = 0;

        foreach ([route('pocetna'), route('prilike.index'), route('prilike.show', $prilika->slug), route('vodici.index'), route('organizacije.index'), route('kalendar.index'), route('pomocnik.index'), '/nema-te-strane'] as $adresa) {
            $html = $this->get($adresa)->getContent();
            preg_match_all('#<a class="logo" href="([^"]*)">(.*?)</a>#s', $html, $logoi, PREG_SET_ORDER);

            $this->assertCount(1, $logoi, $adresa);
            [, $veza, $unutra] = $logoi[0];
            $this->assertSame(route('pocetna'), $veza);
            $this->assertStringContainsString('<svg class="logo-znak" width="34" height="34" viewBox="0 0 64 64" aria-hidden="true" focusable="false">', $unutra);
            // Luk rama je boje slova zaglavlja, krilo ima preliv narandžaste u zlato, kvaka je noć.
            $this->assertStringContainsString('<path d="M12 60V28C12 15 21 6 32 6s20 9 20 22v32" fill="none" stroke="currentColor"', $unutra);
            $this->assertStringContainsString('<path d="M32 14l13 6v40H32z" fill="url(#logo-krilo)"/>', $unutra);
            $this->assertStringContainsString('<stop offset="0" stop-color="#e07229"/><stop offset="1" stop-color="#e7b53c"/>', $unutra);
            $this->assertStringContainsString('<circle cx="41" cy="40" r="2.6" fill="#270f43"/>', $unutra);
            $this->assertStringContainsString('<span class="logo-ime">'.config('app.name').' <small>'.PrikazZaglavlja::SLOGAN.'</small></span>', $unutra);
            // Znak je ukras: čitač ekrana čita samo ime i slogan.
            $this->assertSame('Putardo Vudar Otvorena vrata', trim((string) preg_replace('/\s+/', ' ', strip_tags($unutra))));
            $this->assertStringNotContainsString('<title', $unutra);
            $this->assertStringNotContainsString('href="http', preg_replace('#<a class="logo" href="[^"]*">#', '', $unutra));
            $obidjeno++;
        }

        $this->assertSame(8, $obidjeno);
        $this->assertSame('Otvorena vrata', PrikazZaglavlja::SLOGAN);
    }

    #[Test]
    public function stil_slaze_znak_uz_ime_a_slogan_je_ispod_imena_i_prati_boju_zaglavlja(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.logo \{\s*padding: 8px 0;\s*display: flex;\s*align-items: center;\s*gap: 10px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.logo-znak \{[^}]*flex: none;[^}]*color: inherit;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.logo small \{[^}]*display: block;[^}]*font-size: 10\.5px;[^}]*letter-spacing: 0\.16em;/', $stil);
        // Na vrhu strane (providno zaglavlje) i ime i slogan su beli; posle skrolovanja važi osnovna boja.
        $this->assertMatchesRegularExpression('/\.js \.ima-traku \.zaglavlje:not\(\.skrolovano\) \.logo,[^{]*\{\s*color: #fff;/', $stil);
        $this->assertMatchesRegularExpression('/\.js \.ima-traku \.zaglavlje:not\(\.skrolovano\) \.logo small \{\s*color: rgba\(255, 255, 255, 0\.7\);/', $stil);
        // Ogledalo: stari naziv za drugi red imena (span u vezi) više nema pravila.
        $this->assertStringNotContainsString('.logo span', $stil);
    }
}
