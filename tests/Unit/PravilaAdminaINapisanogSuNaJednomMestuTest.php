<?php

namespace Tests\Unit;

use App\Models\Predlog;
use App\Support\PrikazPredloga;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 97/T2 (nalaz 8 iz PITANJA-92): pravilo koje je stajalo na dva mesta stoji na jednom. Merila gledaju kod bez komentara, a svako ima ogledalo na izmišljenom kodu i dokaz da je obišlo neprazan skup.
class PravilaAdminaINapisanogSuNaJednomMestuTest extends TestCase
{
    private const SIRINA = 'flex: 1 1 0; min-width: 0';

    /** @return list<string> putanje (od korena projekta) svih php fajlova u folderu */
    private function fajlovi(string $folder): array
    {
        $koren = dirname(__DIR__, 2);
        $nadjeno = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($koren.'/'.$folder, \FilesystemIterator::SKIP_DOTS)) as $fajl) {
            if ($fajl->getExtension() === 'php') {
                $nadjeno[] = substr($fajl->getPathname(), strlen($koren) + 1);
            }
        }

        sort($nadjeno);

        return $nadjeno;
    }

    private function kod(string $putanja): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$putanja);
    }

    /** @return list<array{int, string}> tokeni bez razmaka i komentara */
    private function tokeni(string $kod): array
    {
        $tokeni = [];

        foreach (token_get_all($kod) as $token) {
            $token = is_array($token) ? [$token[0], $token[1]] : [0, $token];

            if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokeni[] = $token;
            }
        }

        return $tokeni;
    }

    // Koliko puta kod zove Klasa::clan (bilo koji clan ako je null).
    private function pozivi(string $kod, string $klasa, ?string $clan = null): int
    {
        $tokeni = $this->tokeni($kod);
        $broj = 0;

        foreach ($tokeni as $i => [$vrsta, $tekst]) {
            if ($vrsta === T_STRING && $tekst === $klasa && ($tokeni[$i + 1][0] ?? null) === T_DOUBLE_COLON && ($clan === null || ($tokeni[$i + 2][1] ?? null) === $clan)) {
                $broj++;
            }
        }

        return $broj;
    }

    /** @return list<string> tekstovi (navodnici) u kodu */
    private function tekstovi(string $kod): array
    {
        return array_map(fn (array $token) => trim($token[1], '\'"'), array_filter($this->tokeni($kod), fn (array $token) => $token[0] === T_CONSTANT_ENCAPSED_STRING));
    }

    private function imaFunkciju(string $kod, string $ime): bool
    {
        $tokeni = $this->tokeni($kod);

        foreach ($tokeni as $i => [$vrsta]) {
            if ($vrsta === T_FUNCTION && ($tokeni[$i + 1][1] ?? null) === $ime) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function vrednost_iz_forme_se_pretvara_u_status_samo_u_enumu(): void
    {
        $obidjeno = 0;
        $pozivaju = [];

        foreach ($this->fajlovi('app') as $fajl) {
            $obidjeno++;
            $kod = $this->kod($fajl);

            $this->assertSame(0, $this->pozivi($kod, 'StatusObjave', 'tryFrom'), $fajl);
            $this->assertFalse($this->imaFunkciju($kod, 'statusJeObjavljeno'), $fajl);

            if ($this->pozivi($kod, 'StatusObjave', 'jeJavnaVrednost') > 0) {
                $pozivaju[] = $fajl;
            }
        }

        $this->assertGreaterThan(100, $obidjeno);
        $this->assertSame(['app/Filament/Resources/Kalendar/Schemas/KalendarForm.php', 'app/Filament/Resources/Organizacije/Schemas/OrganizacijaForm.php'], $pozivaju);
    }

    #[Test]
    public function sirina_dugmadi_stoji_u_jednom_fajlu_a_koriste_je_oba_spiska(): void
    {
        $saNizom = [];
        $koriste = [];

        foreach ($this->fajlovi('app') as $fajl) {
            $kod = $this->kod($fajl);

            if (array_filter($this->tekstovi($kod), fn (string $tekst) => str_contains($tekst, self::SIRINA)) !== []) {
                $saNizom[] = $fajl;
            }

            if ($this->pozivi($kod, 'DugmadNaKartici', 'SIRINA') > 0) {
                $koriste[] = $fajl;
            }
        }

        $this->assertSame(['app/Filament/DugmadNaKartici.php'], $saNizom);
        $this->assertSame(['app/Filament/Resources/Predlozi/Tables/PredloziTable.php', 'app/Filament/Resources/Prilike/Tables/PrilikeTable.php'], $koriste);
    }

    #[Test]
    public function ime_se_poredi_na_jednom_mestu_u_proveri_napisanog_teksta(): void
    {
        $kod = $this->kod('app/Services/Pomocnik/ProveraNapisanogTeksta.php');

        $this->assertSame(1, $this->pozivi($kod, 'Latinica', 'izCirilice'));
        $this->assertTrue($this->imaFunkciju($kod, 'zaPoredjenjeImena'));
    }

    // Granice dužine su u modelu Predlog; tekstovi koje posetilac vidi se sklapaju iz njih, pa kad se granica promeni, tekst ne ostaje star.
    #[Test]
    public function brojevi_granica_predloga_nisu_upisani_u_tekstove(): void
    {
        $kod = $this->kod('app/Support/PrikazPredloga.php');

        $this->assertSame([], array_values(array_filter($this->tokeni($kod), fn (array $token) => $token[0] === T_LNUMBER)));
        $this->assertSame([], array_values(array_filter($this->tekstovi($kod), fn (string $tekst) => preg_match('/\d{3,}/', $tekst) === 1)));
        $this->assertGreaterThanOrEqual(3, $this->pozivi($kod, 'Predlog'));

        // Sklopljeni tekstovi nose broj iz modela.
        $this->assertStringContainsString((string) Predlog::NAJVISE_ZNAKOVA_NAPOMENE, PrikazPredloga::OBJASNJENJE_NAPOMENE);
        $this->assertStringContainsString((string) Predlog::NAJVISE_ZNAKOVA_NAPOMENE, PrikazPredloga::GRESKA_NAPOMENA_DUGACKA);
        $this->assertStringContainsString((string) Predlog::NAJVISE_ZNAKOVA_LINKA, PrikazPredloga::GRESKA_LINK_DUGACAK);
    }

    // Ogledalo: merila vide dvostruko pravilo kad ga ima, a ne vide ga u komentaru.
    #[Test]
    public function merila_prepoznaju_dvostruko_pravilo_a_ne_broje_komentare(): void
    {
        $dvostruko = "<?php\nclass A { private const S = ['style' => 'flex: 1 1 0; min-width: 0; x']; private static function statusJeObjavljeno(\$s) { return StatusObjave::tryFrom(\$s); } const B = 'Najviše 500 znakova'; const N = 500; }";
        $komentar = "<?php\n// flex: 1 1 0; min-width: 0; StatusObjave::tryFrom; statusJeObjavljeno; 500\nclass A {}";

        $this->assertNotEmpty(array_filter($this->tekstovi($dvostruko), fn (string $tekst) => str_contains($tekst, self::SIRINA)));
        $this->assertSame(1, $this->pozivi($dvostruko, 'StatusObjave', 'tryFrom'));
        $this->assertTrue($this->imaFunkciju($dvostruko, 'statusJeObjavljeno'));
        $this->assertNotEmpty(array_filter($this->tokeni($dvostruko), fn (array $token) => $token[0] === T_LNUMBER));
        $this->assertNotEmpty(array_filter($this->tekstovi($dvostruko), fn (string $tekst) => preg_match('/\d{3,}/', $tekst) === 1));

        $this->assertSame([], array_filter($this->tekstovi($komentar), fn (string $tekst) => str_contains($tekst, self::SIRINA)));
        $this->assertSame(0, $this->pozivi($komentar, 'StatusObjave', 'tryFrom'));
        $this->assertFalse($this->imaFunkciju($komentar, 'statusJeObjavljeno'));
        $this->assertSame([], array_filter($this->tokeni($komentar), fn (array $token) => $token[0] === T_LNUMBER));
    }
}
