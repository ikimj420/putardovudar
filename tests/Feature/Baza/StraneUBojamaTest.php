<?php

namespace Tests\Feature\Baza;

use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57 T5: svaka strana ima svoju boju — prilike narandžasta, vodiči ljubičasta, organizacije bobica, kalendar zlato; početna i pomoćnik su noć.
// Naslovni deo dobija sjaj u boji strane, aktivna stavka menija i plutajući pomoćnik njenu boju (na kalendaru i narandžastoj tamna slova).
#[Group('baza')]
class StraneUBojamaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    /** @return array<string, string|null> adresa => očekivana boja strane (null = noć) */
    private function strane(): array
    {
        $prilika = Prilika::factory()->objavljena()->create();
        $vodic = Vodic::factory()->objavljen()->create();
        $organizacija = Organizacija::factory()->objavljena()->create();
        KalendarStavka::factory()->objavljena()->create();

        return [
            route('prilike.index') => 'prilike', route('prilike.show', $prilika->slug) => 'prilike', route('prilike.show', 'nema-je') => 'prilike',
            route('vodici.index') => 'vodici', route('vodici.show', $vodic->slug) => 'vodici',
            route('organizacije.index') => 'organizacije', route('organizacije.show', $organizacija->slug) => 'organizacije',
            route('kalendar.index') => 'kalendar',
            route('pocetna') => null, route('pomocnik.index') => null, '/nema-te-strane' => null,
        ];
    }

    #[Test]
    public function strana_nosi_svoju_boju_a_pocetna_i_pomocnik_je_nemaju(): void
    {
        $strane = $this->strane();

        foreach ($strane as $adresa => $boja) {
            $html = $this->get($adresa)->getContent();
            preg_match('/<body([^>]*)>/', $html, $telo);

            $this->assertSame($boja === null ? 0 : 1, preg_match('/data-strana="'.($boja ?? 'x').'"/', $telo[1]), $adresa.' '.$telo[1]);
            $this->assertSame(substr_count($telo[1], 'data-strana'), $boja === null ? 0 : 1, $adresa);
        }

        $this->assertCount(11, $strane);
        $this->assertSame(['prilike', 'vodici', 'organizacije', 'kalendar'], array_values(array_unique(array_filter($strane))));
    }

    // Aktivna stavka menija je ona strana na kojoj je posetilac (aria-current), samo jedna; na početnoj i pomoćniku nijedna.
    #[Test]
    public function aktivna_stavka_menija_je_jedna_i_to_trenutna_strana(): void
    {
        $adrese = ['prilike' => route('prilike.index'), 'vodici' => route('vodici.index'), 'organizacije' => route('organizacije.index'), 'kalendar' => route('kalendar.index')];
        $this->strane();

        foreach ($adrese as $strana => $adresa) {
            preg_match_all('#<a href="([^"]+)"( aria-current="page")?>#', (string) $this->get($adresa)->getContent(), $veze, PREG_SET_ORDER);
            $aktivne = array_values(array_filter($veze, fn (array $veza) => isset($veza[2])));

            $this->assertCount(1, $aktivne, $strana);
            $this->assertSame($adresa, $aktivne[0][1], $strana);
        }

        foreach ([route('pocetna'), route('pomocnik.index')] as $adresa) {
            $this->assertStringNotContainsString('aria-current="page"', $this->get($adresa)->getContent(), $adresa);
        }
    }

    /** @return array<string, array{0: string, 1: string}> strana => [sjaj, slova] */
    private function bojeStrana(): array
    {
        return ['prilike' => ['rgba(224, 114, 41, 0.6)', 'var(--noc)'], 'vodici' => ['rgba(101, 31, 199, 0.8)', '#ffffff'], 'organizacije' => ['rgba(184, 60, 126, 0.75)', '#ffffff'], 'kalendar' => ['rgba(231, 181, 60, 0.5)', 'var(--noc)']];
    }

    private function hex(string $naziv): string
    {
        preg_match('/--'.preg_quote($naziv, '/').': (#[0-9a-f]{6});/', $this->stilBezKomentara(), $nalaz);

        return $nalaz[1] ?? '';
    }

    private function osvetljenost(string $boja): float
    {
        [$r, $g, $b] = array_map(fn (string $c) => (hexdec($c) / 255.0) <= 0.03928 ? (hexdec($c) / 255.0) / 12.92 : (((hexdec($c) / 255.0) + 0.055) / 1.055) ** 2.4, str_split(ltrim($boja, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function kontrast(string $prva, string $druga): float
    {
        return (max($this->osvetljenost($prva), $this->osvetljenost($druga)) + 0.05) / (min($this->osvetljenost($prva), $this->osvetljenost($druga)) + 0.05);
    }

    // Stil: svaka strana menja sjaj naslovnog dela, podlogu aktivne stavke i plutajućeg dugmeta; slova na podlozi imaju kontrast najmanje 4,5 : 1 na oba kraja preliva.
    #[Test]
    public function stil_daje_svakoj_strani_sjaj_podlogu_i_slova_sa_kontrastom(): void
    {
        $stil = $this->stilBezKomentara();
        $slova = ['var(--noc)' => $this->hex('noc'), '#ffffff' => '#ffffff'];
        $obidjeno = 0;

        foreach ($this->bojeStrana() as $strana => [$sjaj, $slovaBoja]) {
            preg_match('/\nbody\[data-strana="'.$strana.'"\] \{([^}]*)\}/', $stil, $blok);
            $this->assertNotEmpty($blok, $strana);

            $this->assertStringContainsString('--sjaj-2: '.$sjaj.';', $blok[1], $strana);
            $this->assertStringContainsString('--strana-slova: '.$slovaBoja.';', $blok[1], $strana);
            preg_match('/--strana-pozadina: linear-gradient\(135deg, var\(--([a-z-]+)\), var\(--([a-z-]+)\)\);/', $blok[1], $preliv);
            $this->assertCount(3, $preliv, $strana);

            foreach ([$preliv[1], $preliv[2]] as $kraj) {
                $this->assertGreaterThanOrEqual(4.5, $this->kontrast($slova[$slovaBoja], $this->hex($kraj)), $strana.' na '.$kraj);
            }
            $obidjeno++;
        }

        $this->assertSame(4, $obidjeno);
        // Naslovni deo, aktivna stavka i plutajuće dugme čitaju te promenljive.
        $this->assertStringContainsString('radial-gradient(80% 80% at 95% 0%, var(--sjaj-2), transparent 58%)', $stil);
        $this->assertMatchesRegularExpression('/\n\.meni a\[aria-current="page"\] \{\s*background: var\(--strana-pozadina\);\s*color: var\(--strana-slova\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-dugme \{[^}]*background: var\(--strana-pozadina\);[^}]*color: var\(--strana-slova\);/', $stil);
        // Dugmad „Pozovi", „Viber" i ostala (.dugme-veza) su u boji strane, a na dodir noć (ljubičasta tamo gde je podloga već noć).
        $this->assertMatchesRegularExpression('/\n\.dugme-veza \{[^}]*background: var\(--strana-pozadina\);[^}]*color: var\(--strana-slova\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-veza:hover \{\s*background: var\(--strana-nad\);\s*color: #fff;/', $stil);
        $this->assertStringContainsString('--strana-nad: var(--ljubicasta);', $stil);
        foreach (['prilike', 'vodici', 'organizacije', 'kalendar'] as $strana) {
            preg_match('/\nbody\[data-strana="'.$strana.'"\] \{([^}]*)\}/', $stil, $blok);
            $this->assertStringContainsString('--strana-nad: var(--noc);', $blok[1], $strana);
        }
        $this->assertMatchesRegularExpression('/\n\.plutajuci-krug \{[^}]*background: var\(--strana-krug\);[^}]*color: var\(--strana-ikona\);/', $stil);
        // Ogledalo: bela slova na narandžastoj podlozi (kako je u predlogu) ne bi prošla, pa narandžasta i kalendar nose tamna slova.
        $this->assertLessThan(4.5, $this->kontrast('#ffffff', $this->hex('narandzasta')));
        $this->assertLessThan(4.5, $this->kontrast('#ffffff', $this->hex('zlato')));
    }

    // Početna i pomoćnik (bez boje strane) ostaju noć: podrazumevana podloga dugmeta je noć, krug narandžasto-zlatan, slova bela.
    #[Test]
    public function bez_boje_strane_dugme_je_noc_sa_narandzasto_zlatnim_krugom(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertStringContainsString('--strana-pozadina: rgba(39, 15, 67, 0.82);', $stil);
        $this->assertStringContainsString('--strana-slova: #ffffff;', $stil);
        $this->assertStringContainsString('--strana-krug: linear-gradient(135deg, var(--narandzasta), var(--zlato));', $stil);
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast('#ffffff', $this->hex('noc')));
    }
}
