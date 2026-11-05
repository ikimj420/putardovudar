<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use Database\Seeders\SabloniSeeder;
use Database\Seeders\VodiciPrimeriSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 101/T2 (Ivan, 05.11.2026., PITANJA-100 2): slobodan tekst ide Claude-u doslovno, a kod u njemu ne prepoznaje ime,
// pa odmah ispod polja „tekst" stoji Ivanova rečenica. Rečenica je u prikazu, pa važi i za šablone koji su već u bazi.
#[Group('baza')]
class SlobodanTekstUNapisiMojNosiNapomenuTest extends BazaTestCase
{
    use RefreshDatabase;

    private const NAPOMENA = '<p class="privatnost">Ne upisuj ovde ime, adresu ni telefon.</p>';

    /** @param  array<string, mixed>  $podaci */
    private function forma(array $podaci): string
    {
        $sablon = Sablon::factory()->objavljen()->create(array_intersect_key($podaci, array_flip(['naslov', 'slug', 'objasnjenje', 'polja', 'tekst'])));

        return (string) $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent();
    }

    #[Test]
    public function recenica_stoji_jednom_odmah_ispod_slobodnog_teksta(): void
    {
        $pregledano = 0;

        foreach (SabloniSeeder::sabloni() as $podaci) {
            $forma = $this->forma($podaci);
            $kljucevi = array_column($podaci['polja'], 'kljuc');
            $sledece = $kljucevi[(int) array_search('tekst', $kljucevi, true) + 1];

            $this->assertSame(1, substr_count($forma, self::NAPOMENA), $podaci['slug']);
            $this->assertTrue(strpos($forma, 'id="polje-tekst"') < strpos($forma, self::NAPOMENA), $podaci['slug'].': rečenica stoji iznad polja');
            $this->assertTrue(strpos($forma, self::NAPOMENA) < strpos($forma, 'id="polje-'.$sledece.'"'), $podaci['slug'].': rečenica nije odmah ispod polja');
            $pregledano++;
        }

        // Neprazan skup: oba šablona iz seedera imaju polje „tekst"; „zahtev" ima i drugo dugo polje (prilozi), ispod kojeg rečenice nema.
        $this->assertSame(2, $pregledano);
        $this->assertContains('prilozi', array_column(SabloniSeeder::sabloni()[1]['polja'], 'kljuc'));
    }

    // Ogledalo: šablon bez polja „tekst" nema rečenicu, ni ispod svojih dugih polja; nema je ni na strani pomoćnika.
    #[Test]
    public function bez_polja_tekst_recenice_nema(): void
    {
        $pregledano = 0;
        $dugih = 0;

        foreach (VodiciPrimeriSeeder::sabloni() as $podaci) {
            $this->assertNotContains('tekst', array_column($podaci['polja'], 'kljuc'), $podaci['slug']);
            $this->assertStringNotContainsString('Ne upisuj ovde ime', $this->forma($podaci), $podaci['slug']);
            $dugih += count(array_filter($podaci['polja'], fn (array $polje) => $polje['dugo']));
            $pregledano++;
        }

        $this->assertSame(6, $pregledano);
        $this->assertGreaterThan(0, $dugih);
        $this->get(route('pomocnik.index'))->assertOk()->assertDontSee('Ne upisuj ovde ime');
    }
}
