<?php

namespace Tests\Unit;

use App\Services\NaslovOglasa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 63/T3: reči u naslovu koje kažu da tekst nije oglas za posao nego rezultat ili spisak; spisak reči je na jednom mestu (NaslovOglasa).
class NaslovOglasaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function naslovi(): array
    {
        return [
            'rezultat' => ['Rezultat konkursa', true],
            'rezultati' => ['Objavljeni rezultati za posao', true],
            'lista' => ['Lista kandidata', true],
            'liste' => ['Konačne liste prijavljenih', true],
            'na listi' => ['Kandidati na listi čekanja', true],
            'izabran' => ['Izabrani kandidati', true],
            'izabrana' => ['Izabrana kandidatkinja za radno mesto', true],
            'dobitnik' => ['Dobitnik nagrade', true],
            'dobitnici' => ['Dobitnici stipendije', true],
            'dobitnica' => ['Dobitnica nagrade', true],
            'velika slova' => ['REZULTATI KONKURSA', true],
            'sa dijakriticima' => ['Lista kandidata za šefa smene', true],
            // Ogledalo: obični naslovi oglasa prolaze, i reči koje samo liče na spisak.
            'oglas' => ['Magacioner', false],
            'šef smene' => ['Šef smene u proizvodnom pogonu', false],
            'listing nije lista' => ['Listing agent u kancelariji', false],
            'listić nije lista' => ['Prodavac listića', false],
            'prazan naslov' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('naslovi')]
    public function reci_rezultat_lista_izabran_i_dobitnik_u_naslovu_nisu_oglas(string $naslov, bool $jeRezultat): void
    {
        $this->assertSame($jeRezultat, NaslovOglasa::jeRezultatIliSpisak($naslov), $naslov);
    }

    // Spisak reči je na jednom mestu: nijedan drugi fajl u aplikaciji ne nabraja ove reči kao stabla za proveru naslova.
    #[Test]
    public function spisak_reci_je_samo_u_klasi_naslov_oglasa(): void
    {
        $nalazi = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', \FilesystemIterator::SKIP_DOTS)) as $fajl) {
            if (str_contains((string) file_get_contents($fajl->getPathname()), 'dobitni(?:k|c)')) {
                $nalazi[] = basename($fajl->getPathname());
            }
        }

        $this->assertSame(['NaslovOglasa.php'], $nalazi);
    }
}
