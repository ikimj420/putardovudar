<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 35: pristupačnost javnih strana u stilu. Boje se računaju (kontrast, WCAG AA 4,5 : 1), a veličine ciljeva i fokus
// su zaključani u stilu; stvarna veličina i fokus mere se u pregledaču (izveštaj paketa 35).
class PristupacnostJavnihStranaTest extends TestCase
{
    use StilJavnihStrana;

    // Svaki način da se obris fokusa ugasi: none, 0 (sa jedinicom ili bez), providna boja, stil none, širina 0.
    private const GASI_OBRIS = '/outline(?:-style|-width|-color)?:\s*(?:[^;}]*\b)?(?:none|0(?:px)?|transparent)\b/';

    private function stil(): string
    {
        return $this->stilBezKomentara();
    }

    private function boja(string $naziv): string
    {
        preg_match('/--'.preg_quote($naziv, '/').': (#[0-9a-f]{6});/i', $this->stil(), $nalaz);

        return $nalaz[1] ?? '';
    }

    private function osvetljenost(string $boja): float
    {
        $kanali = array_map(fn (string $c) => hexdec($c) / 255, str_split(ltrim($boja, '#'), 2));
        [$r, $g, $b] = array_map(fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $kanali);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function kontrast(string $prva, string $druga): float
    {
        [$svetla, $tamna] = [max($this->osvetljenost($prva), $this->osvetljenost($druga)), min($this->osvetljenost($prva), $this->osvetljenost($druga))];

        return ($svetla + 0.05) / ($tamna + 0.05);
    }

    /** @return array<string, array{0: string, 1: string}> tekst, pozadina */
    public static function tekstoviNaPozadinama(): array
    {
        $pozadine = ['page' => '--page', 'card-bg' => '--card-bg', 'input-bg' => '--input-bg'];
        $tekstovi = ['text' => '--text', 'muted' => '--muted', 'blue-text' => '--blue-text'];
        $skup = [];

        foreach ($tekstovi as $tekst => $x) {
            foreach ($pozadine as $pozadina => $y) {
                $skup["$tekst na $pozadina"] = [$tekst, $pozadina];
            }
        }

        return $skup;
    }

    #[Test]
    #[DataProvider('tekstoviNaPozadinama')]
    public function tekst_na_pozadini_ima_kontrast_najmanje_cetiri_i_po_prema_jedan(string $tekst, string $pozadina): void
    {
        $this->assertNotSame('', $this->boja($tekst));
        $this->assertNotSame('', $this->boja($pozadina));
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast($this->boja($tekst), $this->boja($pozadina)), "$tekst na $pozadina");
    }

    // Ogledalo: merilo vidi lošu boju. Prototipska --blue-hover (#347ed3) ne ispunjava AA za tekst, zato tekst koristi --blue-text.
    #[Test]
    public function merilo_kontrasta_prepoznaje_prototipsku_plavu_kao_preslabu_za_tekst(): void
    {
        $this->assertLessThan(4.5, $this->kontrast($this->boja('blue-hover'), $this->boja('page')));
        $this->assertLessThan(4.5, $this->kontrast($this->boja('blue-hover'), $this->boja('card-bg')));
        $this->assertGreaterThan(4.5, $this->kontrast($this->boja('blue-text'), $this->boja('page')));
        $this->assertEqualsWithDelta(21.0, $this->kontrast('#000000', '#ffffff'), 0.001);
    }

    #[Test]
    public function tekst_na_aktivnoj_pilula_i_beli_broj_na_plavom_imaju_kontrast_najmanje_cetiri_i_po(): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast($this->boja('dark'), $this->boja('blue')));
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast('#ffffff', $this->boja('blue-text')));
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast($this->boja('button-dark-text'), $this->boja('button-dark-bg')));
    }

    #[Test]
    public function veze_i_dugmad_na_telefonu_imaju_cilj_od_najmanje_44_px(): void
    {
        $stil = $this->stil();
        $ciljevi = ['.dugme-meni' => 'width: 44px;[^}]*height: 44px;', '.filter-pilula' => 'min-height: 44px;', '.mrvice a' => 'min-width: 44px;\s*min-height: 44px;', '.meni-sredina a' => 'min-width: 44px;\s*min-height: 44px;', '.pitanje button' => 'min-height: 44px;'];

        foreach ($ciljevi as $izbor => $pravilo) {
            $this->assertMatchesRegularExpression('/(?:^|\n)'.preg_quote($izbor, '/').' \{[^}]*'.$pravilo.'/s', $stil, $izbor);
        }

        // Veza naslova kartice i ime sajta dopunjuju visinu razmakom: 9 + 9 px uz red teksta.
        $this->assertMatchesRegularExpression('/\.kartica h2 a,\s*\.kartica h3 a \{[^}]*display: inline-block;\s*padding: 9px 0;\s*margin: -9px 0;/s', $stil);
        $this->assertMatchesRegularExpression('/\.logo \{\s*padding: 8px 0;/', $stil);
    }

    #[Test]
    public function fokus_je_vidljiv_na_svemu_sto_se_fokusira_obrisom_od_tri_piksela(): void
    {
        $stil = $this->stil();

        $this->assertMatchesRegularExpression('/\n:focus-visible \{\s*outline: 3px solid var\(--blue-hover\);\s*outline-offset: 2px;\s*\}/', $stil);
        // Nijedno pravilo ne gasi obris fokusa, ma kako da je napisano.
        $this->assertDoesNotMatchRegularExpression(self::GASI_OBRIS, $stil);
        // Ogledalo: merilo vidi sve načine da se obris ugasi, a ne vidi običan obris.
        foreach (['outline: none;', 'outline: 0;', 'outline: 0px;', 'outline: transparent;', 'outline: 3px solid transparent;', 'outline-style: none;', 'outline-width: 0;', 'outline-width: 0px;', 'outline-color: transparent;'] as $ugasen) {
            $this->assertSame(1, preg_match(self::GASI_OBRIS, ".x:focus { $ugasen }"), $ugasen);
        }
        $this->assertSame(0, preg_match(self::GASI_OBRIS, '.x:focus { outline: 3px solid var(--blue-hover); outline-offset: 2px; }'));
    }

    // Lepljivo zaglavlje ne sme da zakloni fokusiranu vezu (WCAG 2.4.11): visina izmerena u pregledaču je 57 px, a bez skripta na telefonu 101 px.
    #[Test]
    public function skrolovanje_do_fokusa_ostavlja_mesto_za_lepljivo_zaglavlje(): void
    {
        $stil = $this->stil();

        preg_match('/\nhtml \{\s*scroll-padding-top: (\d+)px;/', $stil, $osnovno);
        preg_match('/@media \(max-width: 1000px\) \{\s*html:not\(\.js\) \{\s*scroll-padding-top: (\d+)px;/', $stil, $bezSkripta);

        $this->assertGreaterThanOrEqual(57, (int) ($osnovno[1] ?? 0));
        $this->assertGreaterThanOrEqual(101, (int) ($bezSkripta[1] ?? 0));
    }

    // Boja teksta je --blue-text, a prototipska --blue-hover (preslaba za tekst) služi samo za obris; test čita upotrebu, ne samo definicije.
    #[Test]
    public function tekst_i_brojevi_koriste_blue_text_a_nijedno_pravilo_ne_boji_tekst_prototipskom_plavom(): void
    {
        $stil = $this->stil();
        $bojaTeksta = '/(?<![\w-])color:\s*var\(--blue-hover\)/';

        $this->assertDoesNotMatchRegularExpression($bojaTeksta, $stil);
        $this->assertMatchesRegularExpression('/\n\.izvor a \{[^}]*(?<![\w-])color: var\(--blue-text\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.meni-sredina a:hover \{\s*color: var\(--blue-text\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.koraci li::before \{[^}]*background: var\(--blue-text\);/', $stil);

        // Ogledalo: merilo vidi boju teksta, a ne vidi boju pozadine ili okvira.
        $this->assertSame(1, preg_match($bojaTeksta, '.x { color: var(--blue-hover); }'));
        $this->assertSame(0, preg_match($bojaTeksta, '.x { background-color: var(--blue-hover); border-color: var(--blue-hover); }'));
    }

    // Okvir polja za pitanje se razlikuje od pozadine bar 3 : 1 (WCAG 1.4.11); prototipska --border je providna i jedva se vidi.
    #[Test]
    public function okvir_polja_ima_kontrast_najmanje_tri_prema_jedan(): void
    {
        $stil = $this->stil();

        $this->assertNotSame('', $this->boja('input-border'));
        $this->assertGreaterThanOrEqual(3.0, $this->kontrast($this->boja('input-border'), $this->boja('input-bg')));
        $this->assertGreaterThanOrEqual(3.0, $this->kontrast($this->boja('input-border'), $this->boja('page')));
        $this->assertMatchesRegularExpression('/\n\.pitanje textarea \{[^}]*border: 1px solid var\(--input-border\);/', $stil);

        // Ogledalo: prototipski okvir (12 % tamne boje preko bele) ne ispunjava 3 : 1, pa merilo ima šta da uhvati.
        $tamna = $this->boja('text');
        $preko = fn (int $kanal) => (int) round(0.12 * hexdec(substr($tamna, 1 + 2 * $kanal, 2)) + 0.88 * 255);
        $slozena = sprintf('#%02x%02x%02x', $preko(0), $preko(1), $preko(2));

        $this->assertLessThan(3.0, $this->kontrast($slozena, '#ffffff'));
    }

    // Na telefonu je „Pomoćnik" veza u meniju kao ostale: visinu daje .meni a (12 + 12 px uz red teksta), a ne .meni-sredina a.
    #[Test]
    public function veze_menija_na_telefonu_imaju_razmak_koji_daje_cilj_od_44_px(): void
    {
        $stil = $this->stil();

        $this->assertMatchesRegularExpression('/@media \(max-width: 1000px\) \{.*?\n  \.meni a \{\s*padding: 12px 0;\s*\}/s', $stil);
        $this->assertMatchesRegularExpression('/\n  \.js \.meni a \{\s*padding: 13px 14px;/', $stil);
    }

    // Pravilo ekrana: na telefonu jedan podatak po redu; od 720 px pilule idu jedna do druge.
    #[Test]
    public function pilule_sa_podacima_su_na_telefonu_jedna_ispod_druge(): void
    {
        $stil = $this->stil();

        $this->assertMatchesRegularExpression('/\n\.oznake,\s*\.meta \{\s*display: flex;\s*flex-direction: column;\s*align-items: flex-start;/', $stil);
        $this->assertMatchesRegularExpression('/@media \(min-width: 720px\) \{\s*\.oznake,\s*\.meta \{\s*flex-direction: row;\s*flex-wrap: wrap;/', $stil);
    }
}
