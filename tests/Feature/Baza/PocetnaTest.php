<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPocetne;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 57 T4: početna ima svoj naslovni deo (noć, zrno, veliki naslov, dugmad, ilustracija vrata sa oznakama vrsta) i najbliže prilike ispod njega.
#[Group('baza')]
class PocetnaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function prilika(VrstaPrilike $vrsta, string $rok = '2026-11-15', string $naslov = 'Prilika'): Prilika
    {
        return Prilika::factory()->objavljena()->create(['vrsta' => $vrsta, 'rok' => $rok, 'naslov' => $naslov.' '.$vrsta->value]);
    }

    private function hero(string $html): string
    {
        preg_match('#<section class="hero zrno">(.*?)</section>#s', $html, $nalaz);

        return $nalaz[1] ?? '';
    }

    /** @return list<string> nazivi oznaka vrsta na ilustraciji vrata, redom */
    private function oznakeNaVratima(string $hero): array
    {
        preg_match_all('#<circle class="tacka" data-vrsta="([a-z]+)"#', $hero, $nalazi);

        return $nalazi[1];
    }

    // Paket 62/T1: naslov „Vrata su otvorena. Izaberi priliku.", tekst ispod njega, vrata, pa „Pitaj Vudar pomoćnika" na dnu; bez natpisa sa vrstama i bez dugmeta „Pogledaj prilike".
    #[Test]
    public function pocetna_ima_naslovni_deo_sa_naslovom_tekstom_vratima_i_dugmetom_za_pomocnika(): void
    {
        $this->prilika(VrstaPrilike::Posao);

        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        $hero = $this->hero($html);
        $tekst = $this->vidljivTekst($hero);

        $this->assertNotSame('', $hero);
        $this->assertStringContainsString('<h1>Vrata su otvorena. <em>Izaberi priliku.</em></h1>', $hero);
        $this->assertStringContainsString('<p>Izaberi u vratima šta tražiš. Uz svaku priliku stoji zvanični izvor.</p>', $hero);
        // Stakleno dugme otvara prozor pomoćnika (bez skripta je veza ka strani pomoćnika) i stoji na dnu naslovnog dela, posle vrata.
        $pitaj = '<a class="dugme dugme-staklo hero-pitaj" href="'.route('pomocnik.index').'" data-pomocnik-otvori aria-controls="pomocnik-prozor">Pitaj Vudar pomoćnika</a>';
        $this->assertStringContainsString($pitaj, $hero);
        $naslov = strpos($hero, '<h1>');
        $vrata = strpos($hero, '<svg class="vrata"');
        $this->assertNotFalse($vrata);
        $this->assertLessThan($vrata, $naslov);
        $this->assertLessThan(strpos($hero, $pitaj), $vrata);
        // Nema natpisa sa vrstama iznad naslova ni dugmeta „Pogledaj prilike", ni glavne veze-dugmeta u naslovnom delu.
        $this->assertStringNotContainsString('hero-natpis', $hero);
        $this->assertStringNotContainsString('Pogledaj prilike', $html);
        $this->assertStringNotContainsString('<a class="dugme dugme-glavno"', $hero);
        $this->assertStringNotContainsString('Poslovi · ', $tekst);
        // Jedan h1 na strani, i nema naslovne trake ostalih strana.
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringNotContainsString('class="naslovna-traka"', $html);
        // Ogledalo: prozor pomoćnika postoji na početnoj, pa dugme ima šta da otvori; a ispod naslovnog dela dugme „Sve prilike" i dalje stoji.
        $this->assertStringContainsString('id="pomocnik-prozor"', $html);
        $this->assertStringContainsString('<a class="dugme dugme-glavno" href="'.route('prilike.index').'">'.PrikazPocetne::SVE_PRILIKE_DUGME.'</a>', $html);
    }

    // Oznake na vratima postoje samo za vrste koje imaju javnu priliku, redom kojim su nabrojane; nacrt i prošao rok ne računaju.
    #[Test]
    public function oznake_vrsta_na_vratima_su_samo_za_vrste_sa_javnom_prilikom(): void
    {
        $this->prilika(VrstaPrilike::Stipendija);
        $this->prilika(VrstaPrilike::Posao);
        Prilika::factory()->create(['vrsta' => VrstaPrilike::Konkurs, 'status' => StatusPrilike::Nacrt, 'rok' => '2026-11-15']);
        $this->prilika(VrstaPrilike::Obuka, '2026-10-01');

        $hero = $this->hero($this->get(route('pocetna'))->assertOk()->getContent());

        $this->assertSame(['posao', 'stipendija'], $this->oznakeNaVratima($hero));
        $this->assertStringContainsString('>Posao</text>', $hero);
        $this->assertStringContainsString('>Stipendija</text>', $hero);
        $this->assertStringNotContainsString('>Konkurs</text>', $hero);
        $this->assertStringNotContainsString('>Obuka</text>', $hero);
        // Natpis sa nazivima vrsta iznad naslova više ne postoji (paket 62).
        $this->assertStringNotContainsString('Poslovi · Stipendije', $hero);
        $this->assertStringNotContainsString('hero-natpis', $hero);
    }

    // Ogledalo: sa svim vrstama su sve oznake, pa i „Drugo"; bez prilika nema oznaka, a vrata stoje.
    #[Test]
    public function sve_vrste_daju_sve_oznake_a_bez_prilika_nema_oznaka(): void
    {
        $prazno = $this->hero($this->get(route('pocetna'))->assertOk()->getContent());
        $this->assertSame([], $this->oznakeNaVratima($prazno));
        $this->assertStringContainsString('<path d="M52 242V118a58 58 0 0 1 116 0v124z" fill="url(#vrata-unutra)"/>', $prazno);

        foreach (VrstaPrilike::cases() as $vrsta) {
            $this->prilika($vrsta);
        }
        $hero = $this->hero($this->get(route('pocetna'))->assertOk()->getContent());

        $this->assertSame(['posao', 'praksa', 'stipendija', 'konkurs', 'obuka', 'drugo'], $this->oznakeNaVratima($hero));
        $this->assertStringNotContainsString('hero-natpis', $hero);
    }

    // Najbliže prilike su ispod naslovnog dela: najviše šest, najbliži rok prvo, bez roka i stalno otvorene na kraju; prošao rok i nacrt se ne vide.
    #[Test]
    public function ispod_naslovnog_dela_je_najvise_sest_prilika_najblizi_rok_prvo(): void
    {
        foreach (range(1, 8) as $dan) {
            $this->prilika(VrstaPrilike::Posao, '2026-11-'.str_pad((string) (20 - $dan), 2, '0', STR_PAD_LEFT), 'Rok'.$dan);
        }
        Prilika::factory()->objavljena()->create(['naslov' => 'Bez roka', 'rok' => null, 'vrsta' => VrstaPrilike::Posao]);
        Prilika::factory()->create(['naslov' => 'Nacrt', 'status' => StatusPrilike::Nacrt, 'rok' => '2026-10-20']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Prosla', 'rok' => '2026-10-01']);

        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        preg_match_all('#<h2><a class="kartica-veza"[^>]*>([^<]*)</a></h2>#', $html, $naslovi);

        // Rok 12.11. je najbliži (dan 8), pa redom; šest kartica.
        $this->assertSame(['Rok8 posao', 'Rok7 posao', 'Rok6 posao', 'Rok5 posao', 'Rok4 posao', 'Rok3 posao'], $naslovi[1]);
        $this->assertStringNotContainsString('Nacrt', $html);
        $this->assertStringNotContainsString('Prosla', $html);
        $this->assertStringNotContainsString('Bez roka', $html);
        $this->assertStringContainsString('<a class="dugme dugme-glavno" href="'.route('prilike.index').'">'.PrikazPocetne::SVE_PRILIKE_DUGME.'</a>', $html);
    }

    // Ogledalo: prilika bez roka ulazi kad ima mesta, i to na kraju; bez prilika stoji poruka, bez dugmeta „Sve prilike".
    #[Test]
    public function bez_roka_ide_na_kraj_a_bez_prilika_stoji_poruka(): void
    {
        $this->prilika(VrstaPrilike::Posao, '2026-11-20', 'Kasno');
        Prilika::factory()->objavljena()->create(['naslov' => 'Bez roka', 'rok' => null, 'vrsta' => VrstaPrilike::Posao]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Stalno', 'rok' => null, 'rok_stalno_otvoren' => true, 'vrsta' => VrstaPrilike::Posao]);
        $this->prilika(VrstaPrilike::Posao, '2026-11-10', 'Rano');

        preg_match_all('#<h2><a class="kartica-veza"[^>]*>([^<]*)</a></h2>#', $this->get(route('pocetna'))->getContent(), $naslovi);
        $this->assertSame(['Rano posao', 'Kasno posao'], array_slice($naslovi[1], 0, 2));
        $this->assertContains('Bez roka', $naslovi[1]);
        $this->assertContains('Stalno', $naslovi[1]);

        Prilika::query()->delete();
        $prazna = $this->get(route('pocetna'))->assertOk()->getContent();
        $this->assertStringContainsString(PrikazPrilike::NEMA_PRILIKA, $prazna);
        $this->assertStringNotContainsString('class="dugme dugme-glavno" href="'.route('prilike.index').'">'.PrikazPocetne::SVE_PRILIKE_DUGME, $prazna);
    }

    // Stil: noć sa sjajevima i zrnom, naslov sa prelivom u drugom redu, dugme; na telefonu vrata na sredini ispod naslova i bez teksta ispod njega, na laptopu tekst levo i vrata desno.
    #[Test]
    public function stil_nosi_noc_sa_sjajevima_zrno_naslov_sa_prelivom_i_ilustraciju(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.naslovna-traka,\n\.hero \{[^}]*radial-gradient\(90% 70% at 14% -10%, var\(--sjaj-1\)[^}]*var\(--noc\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.zrno::before \{[^}]*background-image: url\("\.\.\/img\/zrno\.svg"\);[^}]*mix-blend-mode: overlay;[^}]*opacity: 0\.1;[^}]*pointer-events: none;/', $stil);
        $this->assertFileExists(public_path('img/zrno.svg'));
        $this->assertMatchesRegularExpression('/\n\.hero h1 em \{[^}]*linear-gradient\(100deg, var\(--lila\), var\(--narandzasta-meka\) 70%, var\(--zlato\)\);[^}]*background-clip: text;[^}]*color: transparent;/', $stil);
        // Telefon: jedna kolona, vrata na sredini široka koliko i sadržaj (najviše 380 px), tekst ispod naslova ne postoji.
        $this->assertMatchesRegularExpression('/\n\.hero-red \{[^}]*display: grid;/', $this->stilBezUpita());
        $this->assertMatchesRegularExpression('/\n\.vrata-okvir \{[^}]*justify-self: center;[^}]*width: min\(100%, 380px\);/', $this->stilBezUpita());
        $this->assertMatchesRegularExpression('/\n\.hero p \{\s*display: none;/', $this->stilBezUpita());
        // Laptop: tekst levo, vrata desno (380 px), dugme ispod oba, tekst ispod naslova se vidi.
        $this->assertMatchesRegularExpression('/@media \(min-width: 900px\) \{\s*\.plocice \{[^}]*\}\s*\.vodici-traka \{[^}]*\}\s*\.hero \{[^}]*padding: 170px 0 100px;\s*\}\s*\.hero-red \{[^}]*grid-template-columns: minmax\(0, 1fr\) 380px;[^}]*"pitaj pitaj"[^}]*\}\s*\.hero-tekst \{[^}]*\}\s*\.hero h1 \{\s*font-size: clamp\(48px, 5\.4vw, 62px\);\s*\}\s*\.hero p \{\s*display: block;[^}]*\}\s*\.traka-red \{[^}]*grid-template-columns: minmax\(0, 1fr\) 380px;[^}]*\}\s*\.vrata-okvir \{[^}]*justify-self: end;[^}]*width: 380px;[^}]*\}\s*\.hero \.vrata-okvir \{\s*grid-area: vrata;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-glavno \{[^}]*linear-gradient\(135deg, var\(--narandzasta\)[^}]*color: var\(--noc\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-staklo \{[^}]*backdrop-filter: blur\(12px\);[^}]*color: #fff;/', $stil);
        // Boje oznaka na vratima su iste kao boje vrsta na karticama.
        foreach (['posao' => 'narandzasta', 'stipendija' => 'bobica', 'konkurs' => 'ljubicasta', 'obuka' => 'zlato'] as $vrsta => $boja) {
            $this->assertMatchesRegularExpression('/\n\.tacka\[data-vrsta="'.$vrsta.'"\] \{\s*fill: var\(--'.$boja.'\);/', $stil, $vrsta);
        }
    }

    // Bez spoljnih adresa: zrno je lokalna slika, a svi resursi početne dolaze sa našeg sajta.
    #[Test]
    public function pocetna_ne_zove_spoljne_adrese(): void
    {
        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        $sopstveni = parse_url(route('pocetna'), PHP_URL_HOST);

        preg_match_all('#\b(?:href|src)="([^"]+)"#', $html, $adrese);
        $this->assertNotEmpty($adrese[1]);
        foreach ($adrese[1] as $adresa) {
            $this->assertContains(parse_url($adresa, PHP_URL_HOST), [null, $sopstveni], $adresa);
        }
        $this->assertDoesNotMatchRegularExpression('#(?:https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $this->stilBezKomentara());
    }
}
