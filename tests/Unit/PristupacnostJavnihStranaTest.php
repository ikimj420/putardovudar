<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 35: pristupačnost javnih strana u stilu. Boje se računaju (kontrast, WCAG AA 4,5 : 1), a veličine ciljeva i fokus
// su zaključani u stilu; stvarna veličina i fokus mere se u pregledaču (izveštaj paketa 35).
class PristupacnostJavnihStranaTest extends TestCase
{
    private function stil(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/public/css/javno.css');
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
        // Nijedno pravilo ne gasi obris fokusa.
        $this->assertDoesNotMatchRegularExpression('/outline:\s*(?:none|0)\b/', $stil);
    }
}
