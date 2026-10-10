<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPocetne;
use App\Support\PrikazPomocnika;
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

    #[Test]
    public function pocetna_ima_naslovni_deo_sa_naslovom_opisom_i_dugmadima(): void
    {
        $this->prilika(VrstaPrilike::Posao);

        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        $hero = $this->hero($html);
        $tekst = $this->vidljivTekst($hero);

        $this->assertNotSame('', $hero);
        $this->assertStringContainsString('<h1>'.PrikazPocetne::NASLOV.'<em>'.PrikazPocetne::NASLOV_ISTAKNUTO.'</em></h1>', $hero);
        $this->assertStringContainsString(PrikazPocetne::OPIS, $tekst);
        $this->assertStringContainsString('<a class="dugme dugme-glavno" href="'.route('prilike.index').'">'.PrikazPocetne::PRILIKE_DUGME.'</a>', $hero);
        // Stakleno dugme otvara prozor pomoćnika (bez skripta je veza ka strani pomoćnika).
        $this->assertStringContainsString('<a class="dugme dugme-staklo" href="'.route('pomocnik.index').'" data-pomocnik-otvori aria-controls="pomocnik-prozor">'.PrikazPomocnika::PITAJ_VUDAR.'</a>', $hero);
        $this->assertStringContainsString('<svg class="vrata" viewBox="0 0 220 270" aria-hidden="true" focusable="false">', $hero);
        // Jedan h1 na strani, i nema naslovne trake ostalih strana.
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringNotContainsString('class="naslovna-traka"', $html);
        // Ogledalo: prozor pomoćnika postoji na početnoj, pa dugme ima šta da otvori.
        $this->assertStringContainsString('id="pomocnik-prozor"', $html);
    }

    // Oznake na vratima i natpis iznad naslova postoje samo za vrste koje imaju javnu priliku, redom kojim su nabrojane; nacrt i prošao rok ne računaju.
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
        $this->assertStringContainsString('<div class="hero-natpis">Poslovi · Stipendije</div>', $hero);
        $this->assertStringNotContainsString('Konkursi', $hero);
    }

    // Ogledalo: sa svim vrstama su sve oznake; „Drugo" je na vratima, a u natpisu nema množinu; bez prilika nema ni oznaka ni natpisa, a vrata stoje.
    #[Test]
    public function sve_vrste_daju_sve_oznake_a_bez_prilika_nema_oznaka(): void
    {
        $prazno = $this->hero($this->get(route('pocetna'))->assertOk()->getContent());
        $this->assertSame([], $this->oznakeNaVratima($prazno));
        $this->assertStringNotContainsString('hero-natpis', $prazno);
        $this->assertStringContainsString('<path d="M52 242V118a58 58 0 0 1 116 0v124z" fill="url(#vrata-unutra)"/>', $prazno);

        foreach (VrstaPrilike::cases() as $vrsta) {
            $this->prilika($vrsta);
        }
        $hero = $this->hero($this->get(route('pocetna'))->assertOk()->getContent());

        $this->assertSame(['posao', 'praksa', 'stipendija', 'konkurs', 'obuka', 'drugo'], $this->oznakeNaVratima($hero));
        $this->assertStringContainsString('<div class="hero-natpis">Poslovi · Prakse · Stipendije · Konkursi · Obuke</div>', $hero);
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

    // Stil: noć sa sjajevima i zrnom, veliki naslov sa prelivom u drugom redu, dugmad; na telefonu ilustracija manja i iza teksta.
    #[Test]
    public function stil_nosi_noc_sa_sjajevima_zrno_naslov_sa_prelivom_i_ilustraciju(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.naslovna-traka,\n\.hero \{[^}]*radial-gradient\(90% 70% at 14% -10%, var\(--sjaj-1\)[^}]*var\(--noc\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.zrno::before \{[^}]*background-image: url\("\.\.\/img\/zrno\.svg"\);[^}]*mix-blend-mode: overlay;[^}]*opacity: 0\.1;[^}]*pointer-events: none;/', $stil);
        $this->assertFileExists(public_path('img/zrno.svg'));
        $this->assertMatchesRegularExpression('/\n\.hero h1 em \{[^}]*linear-gradient\(100deg, var\(--lila\), var\(--narandzasta-meka\) 70%, var\(--zlato\)\);[^}]*background-clip: text;[^}]*color: transparent;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.hero \.vrata \{[^}]*position: absolute;[^}]*width: 190px;[^}]*opacity: 0\.55;[^}]*pointer-events: none;/', $stil);
        $this->assertMatchesRegularExpression('/@media \(min-width: 900px\) \{\s*\.hero \{[^}]*padding: 170px 0 100px;\s*\}\s*\.hero h1 \{\s*font-size: 80px;\s*\}\s*\.hero \.vrata \{[^}]*width: 380px;[^}]*opacity: 1;/', $stil);
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
