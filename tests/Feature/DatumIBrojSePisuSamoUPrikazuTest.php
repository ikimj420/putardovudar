<?php

namespace Tests\Feature;

use App\Support\Prikaz;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class DatumIBrojSePisuSamoUPrikazuTest extends TestCase
{
    #[Test]
    public function nijedan_fajl_osim_prikaza_ne_pretvara_datum_ili_broj_u_tekst(): void
    {
        $nalazi = $this->nalaziPoFajlovima(app_path());

        // Dokaz da je skup neprazan i da merilo vidi: samo mesto za prikaz mora da se nađe.
        $this->assertArrayHasKey((new \ReflectionClass(Prikaz::class))->getFileName(), $nalazi);

        unset($nalazi[(new \ReflectionClass(Prikaz::class))->getFileName()]);

        $this->assertSame([], $nalazi);
    }

    #[Test]
    public function merilo_prepoznaje_oba_oblika_u_kodu(): void
    {
        $kod = '<?php $a = number_format(1.5, 1); $b = $d->format("d.m.Y.");';

        $this->assertCount(2, $this->nalazi($kod));
    }

    // Ogledalo: komentar koji objašnjava zabranu nije prekršaj.
    #[Test]
    public function merilo_ne_broji_komentare(): void
    {
        $kod = "<?php\n// number_format i d.m.Y. samo u Prikazu\n/** isoFormat */\n";

        $this->assertSame([], $this->nalazi($kod));
    }

    /** @return array<string, list<string>> */
    private function nalaziPoFajlovima(string $folder): array
    {
        $rezultat = [];
        $obidjeno = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS)) as $fajl) {
            if ($fajl->getExtension() !== 'php') {
                continue;
            }

            $obidjeno++;
            $nalazi = $this->nalazi(file_get_contents($fajl->getPathname()));

            if ($nalazi !== []) {
                $rezultat[$fajl->getPathname()] = $nalazi;
            }
        }

        $this->assertGreaterThan(0, $obidjeno);

        return $rezultat;
    }

    /** @return list<string> */
    private function nalazi(string $kod): array
    {
        $nalazi = [];

        foreach (token_get_all($kod) as $token) {
            if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tekst = $token[1];
            $jeFunkcija = $token[0] === T_STRING && in_array($tekst, ['number_format', 'isoFormat', 'translatedFormat', 'Number'], true);
            $jeOblik = in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && str_contains($tekst, 'd.m.Y');

            if ($jeFunkcija || $jeOblik) {
                $nalazi[] = $tekst;
            }
        }

        return $nalazi;
    }
}
