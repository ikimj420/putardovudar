<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BojeVrata;
use Tests\Concerns\StilJavnihStrana;
use Tests\TestCase;

// Paket 66/T1 (Ivan, 11.10.2026.: „skloni svuda one underline kod linkova"): nijedan link na javnom sajtu nije podvučen, ni stalno ni na prelaz mišem. Link se od teksta
// razlikuje bojom i debljinom slova, a na prelaz mišem menja boju. Crta se skida jednim pravilom za `a`; ovaj test ne dozvoljava da se vrati.
class NijedanLinkNijePodvucenTest extends TestCase
{
    use BojeVrata, StilJavnihStrana;

    private const CRTA = '/text-decoration(?:-line)?\s*:[^;}]*\b(?:underline|overline|line-through)\b/i';

    /** @return list<string> deklaracije koje podvlače (ili precrtavaju), kao tekst */
    private function crte(string $stil): array
    {
        preg_match_all(self::CRTA, $stil, $nalazi);

        return $nalazi[0];
    }

    // Merilo prvo dokazuje da prepoznaje crtu (i u pseudoklasi i u @media) i da ne prijavljuje „none"; tek onda traži u pravom stilu.
    #[Test]
    public function merilo_prepoznaje_crtu_u_svim_oblicima_a_ne_prijavljuje_none(): void
    {
        foreach ([
            'a:hover { text-decoration: underline; }',
            ".kartica a:hover {\n  text-decoration: underline;\n  text-underline-offset: 4px;\n}",
            '@media (min-width: 720px) { .x a { text-decoration-line: underline; } }',
            'a { text-decoration: none underline; }',
            'a { TEXT-DECORATION: Underline dotted }',
            'a { text-decoration: line-through; }',
        ] as $primer) {
            $this->assertNotSame([], $this->crte($primer), $primer);
        }

        foreach (['a { text-decoration: none; }', 'a { text-decoration-line: none; }', '.pocetak { color: red; } .underlined-ime { color: blue; }'] as $primer) {
            $this->assertSame([], $this->crte($primer), $primer);
        }

        $stil = $this->stilBezKomentara();
        $this->assertGreaterThan(5000, strlen($stil));
        $this->assertGreaterThan(8, substr_count($stil, 'text-decoration: none;'), 'stil sadrži „none" pravila koja merilo mora da prođe');
    }

    #[Test]
    public function stil_javnih_strana_nema_nijednu_crtu_ni_stalnu_ni_na_prelaz_misem(): void
    {
        $this->assertSame([], $this->crte($this->stilBezKomentara()));
    }

    // Jedno mesto: pravilo za sve veze. Bez njega bi veza bez svog pravila ostala podvučena kao u pregledaču.
    #[Test]
    public function crta_se_skida_jednim_pravilom_za_svaku_vezu(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\na \{\s*color: inherit;\s*text-decoration: none;\s*\}/', $stil);
        $this->assertSame(1, preg_match_all('/\na \{/', $stil), 'pravilo za „a" stoji na jednom mestu');
    }

    // Bez crte veza usred teksta mora da se razlikuje bojom i debljinom: u kartici izvora, u redu „Zvanični izvor" (kalendar, odgovor pomoćnika) i u njegovom prozoru.
    // Izmereno u pregledaču: ove veze su nasleđivale boju i debljinu reda, pa im je crta bila jedini znak.
    #[Test]
    public function veza_u_redu_sa_izvorom_se_razlikuje_od_teksta_bojom_i_debljinom_i_na_stampi_je_crna(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.izvor a,\n\.red a,\n\.izvor-podatak a \{\s*color: var\(--akcent\);\s*font-weight: 700;\s*\}/', $stil);
        $this->assertMatchesRegularExpression('/\n  a,\n  \.izvor a,\n  \.red a,\n  \.izvor-podatak a \{\s*color: #000;/', $stil);
        // Ogledalo: red je muklom bojom i debljinom 600, a prozor 400, pa bi veza bez ovog pravila bila nevidljiva u tekstu.
        $this->assertMatchesRegularExpression('/\n\.red \{\s*color: var\(--muted\);\s*font-weight: 600;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.izvor-podatak \{[^}]*color: var\(--muted\);/', $stil);
    }

    /** @return array<string, array{0: string}> */
    public static function mestaIzvanStila(): array
    {
        $koren = dirname(__DIR__, 2);
        $mesta = [];

        foreach (array_merge(glob($koren.'/resources/views/javno/*.php') ?: [], glob($koren.'/resources/views/errors/*.php') ?: [], glob($koren.'/resources/views/*.php') ?: [], [$koren.'/public/js/javno.js']) as $fajl) {
            $mesta[str_replace($koren.'/', '', $fajl)] = [$fajl];
        }

        return $mesta;
    }

    // Crta ne sme da stigne ni odvojeno od stila: oznaka <u>, klasa „underline", stil u samom elementu ili skript koji je postavlja.
    #[Test]
    #[DataProvider('mestaIzvanStila')]
    public function ni_pogled_ni_skript_ne_podvlace(string $fajl): void
    {
        $tekst = (string) file_get_contents($fajl);

        $this->assertNotSame('', $tekst);
        $this->assertDoesNotMatchRegularExpression('/<u[\s>]/i', $tekst);
        $this->assertDoesNotMatchRegularExpression('/\bunderline\b/i', $tekst);
        $this->assertDoesNotMatchRegularExpression('/text-decoration|textDecoration/i', $tekst);
    }

    #[Test]
    public function merilo_pogleda_obilazi_pogled_i_skript(): void
    {
        $fajlovi = array_keys(self::mestaIzvanStila());

        $this->assertGreaterThan(20, count($fajlovi));
        $this->assertContains('public/js/javno.js', $fajlovi);
        $this->assertContains('resources/views/javno/raspored.blade.php', $fajlovi);
        $this->assertNotEmpty(array_filter($fajlovi, fn (string $fajl) => str_starts_with($fajl, 'resources/views/errors/')));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> selektor, boja posle prelaza, boja pre prelaza ili prazno ako je nasleđena */
    public static function prelazi(): array
    {
        return [
            'putanja iznad naslova (mrvice)' => ['.mrvice a:hover', 'var(--zlato-meko)', '#fff'],
            'oznake u vratima' => ['.vrsta-veza:hover', 'var(--noc-duboka)', 'var(--noc)'],
            'naslov kartice (h2)' => [".kartica h2 a:hover,\n.kartica h3 a:hover", 'var(--akcent)', ''],
            'stavka menija' => ['.meni-sredina a:hover', 'var(--akcent)', ''],
            'promena roda' => ['.veza-dugme:hover', 'var(--noc)', 'var(--akcent)'],
        ];
    }

    // Na prelaz mišem veza menja boju (to je jedina promena koja ostaje) i boja je druga nego pre prelaza.
    #[Test]
    #[DataProvider('prelazi')]
    public function na_prelaz_misem_veza_menja_boju_a_ne_crtu(string $selektor, string $posle, string $pre): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n'.preg_quote($selektor, '/').' \{\s*(?:background: [^;]+;\s*)?color: '.preg_quote($posle, '/').';/', $stil, $selektor);
        $this->assertNotSame($pre, $posle);
    }

    /** @return array<string, array{0: string|null}> */
    public static function straneSaVratima(): array
    {
        return ['početna' => [null], 'prilike' => ['prilike'], 'kalendar' => ['kalendar'], 'vodiči' => ['vodici'], 'organizacije' => ['organizacije']];
    }

    // Tamnija boja oznake pri prelazu mišem nikad nije slabija od boje u mirovanju: na svakoj strani, širini i oznaci kontrast je bar isti (model iz paketa 62).
    #[Test]
    #[DataProvider('straneSaVratima')]
    public function boja_oznake_na_prelaz_misem_ima_bar_isti_kontrast_kao_u_mirovanju(?string $strana): void
    {
        foreach ([343, 380] as $sirina) {
            $mirovanje = $this->kontrasti($strana, $sirina);
            $prelaz = $this->kontrasti($strana, $sirina, '#1b0a30');

            $this->assertCount(6, $prelaz);
            foreach ($mirovanje as $i => $kontrast) {
                $this->assertGreaterThanOrEqual($kontrast, $prelaz[$i], ($strana ?? 'pocetna').' '.$sirina.' px, oznaka '.($i + 1));
            }
        }
    }

    // Ogledalo: merilo nije prazno; ljubičasta bi na donjim oznakama bila slabija od boje u mirovanju, pa je ne biramo.
    #[Test]
    public function ljubicasta_na_prelaz_misem_bi_bila_slabija_pa_je_merilo_osetljivo(): void
    {
        $this->assertLessThan($this->kontrasti('prilike', 343)[5], $this->kontrasti('prilike', 343, '#651fc7')[5]);
        $this->assertSame('#1b0a30', '#'.ltrim($this->noc(), '#'));
    }

    // Boja „noć duboka" iz stila je ona koju merilo koristi.
    private function noc(): string
    {
        preg_match('/--noc-duboka: (#[0-9a-f]{6});/', $this->stilBezKomentara(), $nalaz);

        return $nalaz[1] ?? '';
    }
}
