<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57: novi izgled javnih strana (Dokumentacija/izgled-predlog.html). Slova su lokalni fajlovi sa licencom, a ko traži manje kretanja ne dobija prelaze.
class NoviIzgledStilTest extends TestCase
{
    use StilJavnihStrana;

    private const FONTOVI = '/public/fonts/javno';

    // T1: Inter dolazi iz public/fonts/javno uz licencu, nijedna spoljna adresa; naslovi su Inter (paket 67/T17: bez imena slova čiji fajl nikad nije stigao).
    #[Test]
    public function slova_su_lokalni_fajlovi_sa_licencom_i_bez_spoljnih_adresa(): void
    {
        $koren = dirname(__DIR__, 2);
        $stil = $this->stilBezKomentara();

        preg_match_all('/@font-face \{[^}]*?font-family: ([A-Za-z ]+);[^}]*?src: url\("\.\.\/fonts\/javno\/([a-z0-9.-]+)"\)/', $stil, $slova, PREG_SET_ORDER);

        // Merilo prvo dokazuje da je obišlo @font-face pravila, pa da svaki fajl postoji i nije prazan.
        $this->assertCount(2, $slova);
        foreach ($slova as [, $porodica, $fajl]) {
            $this->assertSame('Inter', $porodica);
            $this->assertFileExists($koren.self::FONTOVI.'/'.$fajl);
            $this->assertGreaterThan(10000, filesize($koren.self::FONTOVI.'/'.$fajl), $fajl);
        }

        $licenca = (string) file_get_contents($koren.self::FONTOVI.'/LICENSE-Inter.txt');
        $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $licenca);
        $this->assertStringContainsString('The Inter Project Authors', $licenca);

        $this->assertMatchesRegularExpression('/--font-tekst: Inter,/', $stil);
        $this->assertMatchesRegularExpression('/--font-naslov: Inter, "Inter Variable", system-ui,/', $stil);
        $this->assertMatchesRegularExpression('/\nbody \{[^}]*font-family: var\(--font-tekst\);/', $stil);
        $this->assertMatchesRegularExpression('/\nh1,\nh2,\nh3 \{\s*font-family: var\(--font-naslov\);\s*font-weight: 800;/', $stil);

        // Ogledalo: nijedan fajl slova ni spoljna adresa slova ne stoje u stilu.
        $this->assertDoesNotMatchRegularExpression('#fonts\.(?:googleapis|gstatic)|https?://#', $stil);
        $this->assertSame([], array_values(array_diff(array_map('basename', glob($koren.self::FONTOVI.'/*.woff2') ?: []), array_column($slova, 2))), 'fajl slova koji stil ne koristi');
    }

    // Paket 67/T17: nijedno pominjanje slova čiji fajl ne postoji; merilo prvo dokazuje da je obišlo fajlove, i to stil.
    #[Test]
    public function nijedno_pominjanje_plus_jakarta_sans_u_public_i_resources(): void
    {
        $koren = dirname(__DIR__, 2);
        $obidjeno = 0;
        $stil = false;
        $nadjeno = [];

        foreach (['public', 'resources'] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($koren.'/'.$folder, \FilesystemIterator::SKIP_DOTS)) as $fajl) {
                if (! $fajl->isFile() || ! in_array($fajl->getExtension(), ['css', 'js', 'php', 'html', 'svg', 'txt', 'md', 'json', 'xml', 'webmanifest'], true)) {
                    continue;
                }

                $obidjeno++;
                $stil = $stil || $fajl->getFilename() === 'javno.css';

                if (stripos((string) file_get_contents($fajl->getPathname()), 'Jakarta') !== false) {
                    $nadjeno[] = substr($fajl->getPathname(), strlen($koren) + 1);
                }
            }
        }

        $this->assertGreaterThan(20, $obidjeno);
        $this->assertTrue($stil);
        $this->assertSame([], $nadjeno);
    }

    // T-merilo: prefers-reduced-motion gasi prelaze, animacije i glatko skrolovanje; merilo prvo dokazuje da prelaza ima.
    #[Test]
    public function ko_trazi_manje_kretanja_ne_dobija_prelaze(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertGreaterThan(5, preg_match_all('/\btransition:/', $stil));
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\) \{\s*\*,\s*\*::before,\s*\*::after \{[^}]*transition: none !important;[^}]*animation: none !important;[^}]*scroll-behavior: auto !important;/', $stil);

        // Ogledalo: pravilo je jedino mesto gde se prelazi gase i važi samo uz taj upit, a ne za sve.
        $this->assertSame(1, substr_count($stil, 'prefers-reduced-motion'));
        $this->assertSame(1, substr_count($stil, 'transition: none !important;'));
    }
}
