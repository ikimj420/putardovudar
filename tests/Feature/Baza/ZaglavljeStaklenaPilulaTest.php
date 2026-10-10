<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57 T2: zaglavlje je staklena pilula; na vrhu strane sa naslovnim delom providno sa belim slovima (samo uz skript), posle skrolovanja staklo uz liniju napretka.
// Bez skripta je odmah staklo. Pravi izgled i skrolovanje mere se u pregledaču (izveštaj paketa 57).
#[Group('baza')]
class ZaglavljeStaklenaPilulaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    #[Test]
    public function strana_sa_naslovnim_delom_ima_klasu_a_strana_bez_njega_nema_i_linija_je_pre_zaglavlja(): void
    {
        $prilika = Prilika::factory()->objavljena()->create();

        foreach ([route('pocetna') => '<section class="hero zrno">', route('prilike.index') => '<section class="naslovna-traka">', route('prilike.show', $prilika->slug) => '<section class="naslovna-traka">', route('vodici.index') => '<section class="naslovna-traka">', route('organizacije.index') => '<section class="naslovna-traka">', route('kalendar.index') => '<section class="naslovna-traka">', route('pomocnik.index') => '<section class="naslovna-traka">'] as $adresa => $naslovniDeo) {
            $html = $this->get($adresa)->assertOk()->getContent();

            // Početna ima svoj naslovni deo (hero), ostale strane naslovnu traku; obe su pod providnim zaglavljem.
            $this->assertMatchesRegularExpression('/<body(?: data-strana="[a-z]+")? class="ima-traku">/', $html, $adresa);
            $this->assertStringContainsString($naslovniDeo, $html, $adresa);
            $this->assertSame(1, substr_count($html, '<div class="linija" aria-hidden="true"></div>'), $adresa);
            $this->assertLessThan(strpos($html, '<header class="zaglavlje">'), strpos($html, '<div class="linija" aria-hidden="true"></div>'), $adresa);
        }

        // Ogledalo: strana bez naslovnog dela (nepostojeća) je običan <body>, pa zaglavlje na njoj nikad nije providno.
        $nema = $this->get('/nema-te-strane')->assertNotFound()->getContent();
        $this->assertStringContainsString('<body>', $nema);
        $this->assertStringNotContainsString('ima-traku', $nema);
        $this->assertStringNotContainsString('<section class="naslovna-traka">', $nema);
    }

    // Bez skripta: osnovno pravilo je staklo (papir 80 %, zamućenje 24 px, tanak obrub, meka senka), a providno važi samo uz skript, na strani sa naslovnim delom i na vrhu.
    #[Test]
    public function osnovno_zaglavlje_je_staklo_a_providno_je_samo_uz_skript_na_vrhu_strane_sa_naslovnim_delom(): void
    {
        $stil = $this->stilBezKomentara();

        preg_match('/\n\.zaglavlje \{([^}]*)\}/', $stil, $osnova);
        $this->assertNotEmpty($osnova);
        foreach (['position: sticky;', 'background: rgba(251, 250, 253, 0.8);', 'backdrop-filter: blur(24px) saturate(1.4);', 'border: 1px solid rgba(26, 26, 28, 0.1);', 'border-radius: 28px;', 'box-shadow: var(--shadow);'] as $trag) {
            $this->assertStringContainsString($trag, $osnova[1], $trag);
        }

        // Svako pravilo koje zaglavlje čini providnim ili belim stoji iza „.js .ima-traku …:not(.skrolovano)".
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $stil, $pravila, PREG_SET_ORDER);
        $providnih = 0;
        foreach ($pravila as [, $izbor, $telo]) {
            if (str_contains($izbor, '.zaglavlje') && preg_match('/(?:background: transparent;|(?<![\w-])color: #fff;|color: rgba\(255, 255, 255, 0\.7\);)/', $telo) === 1) {
                $providnih++;
                foreach (explode(',', $izbor) as $deo) {
                    $this->assertStringContainsString('.js .ima-traku .zaglavlje:not(.skrolovano)', trim($deo), trim($deo));
                }
            }
        }
        $this->assertGreaterThanOrEqual(3, $providnih);
    }

    #[Test]
    public function linija_napretka_je_skrivena_dok_skript_ne_radi_i_nije_u_stampi(): void
    {
        $stil = $this->stilBezKomentara();

        preg_match('/\n\.linija \{([^}]*)\}/', $stil, $linija);
        $this->assertNotEmpty($linija);
        foreach (['position: fixed;', 'top: 0;', 'height: 3px;', 'linear-gradient(90deg, var(--ljubicasta), var(--narandzasta) 60%, var(--zlato))', 'opacity: 0;', 'transform: scaleX(0);', 'transform-origin: left;'] as $trag) {
            $this->assertStringContainsString($trag, $linija[1], $trag);
        }
        $this->assertStringContainsString('.linija,', substr($stil, (int) strpos($stil, '@media print')));
    }

    #[Test]
    public function skript_menja_klasu_i_liniju_pri_skrolovanju_i_ne_zove_spoljne_adrese(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        foreach ([
            "document.querySelector('.zaglavlje')", "document.querySelector('.linija')", "zaglavlje.classList.toggle('skrolovano', vrh > 40);",
            "linija.style.opacity = vrh > 40 ? '1' : '0';", "linija.style.transform = 'scaleX('", 'Math.min(1, vrh / najvise)',
            "window.addEventListener('scroll', osvezi, { passive: true });", 'osvezi();',
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $skript);
    }

    // Lepljivo zaglavlje je pilula 12 px od vrha; fokusirana veza ne sme da stane ispod nje (WCAG 2.4.11), pa je razmak veći od visine pilule.
    #[Test]
    public function skrolovanje_do_fokusa_ostavlja_mesto_za_pilulu(): void
    {
        $stil = $this->stilBezKomentara();

        preg_match('/\nhtml \{\s*scroll-padding-top: (\d+)px;/', $stil, $osnovno);

        // 12 px od vrha, 58 px visine (56 + obrub); naslovni deo je povučen pod zaglavlje za 70 px.
        $this->assertGreaterThanOrEqual(12 + 58 + 16, (int) ($osnovno[1] ?? 0));
        $this->assertMatchesRegularExpression('/\n\.naslovna-traka,\n\.hero \{[^}]*margin-top: -70px;/', $stil);
    }
}
