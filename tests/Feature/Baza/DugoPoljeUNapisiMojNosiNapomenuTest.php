<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use Database\Seeders\SabloniSeeder;
use Database\Seeders\VodiciPrimeriSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 101/T2 (Ivan, 05.11.2026., PITANJA-100 2): slobodan tekst ide Claude-u doslovno, a kod u njemu ne prepoznaje ime, pa ispod
// polja „tekst" stoji Ivanova rečenica. Paket 102/T4 (Ivan „piši", PITANJA-101 4): ista rečenica stoji ispod svakog dugog polja.
// Rečenica je u prikazu, pa važi i za šablone koji su već u bazi.
#[Group('baza')]
class DugoPoljeUNapisiMojNosiNapomenuTest extends BazaTestCase
{
    use RefreshDatabase;

    private const NAPOMENA = '<p class="privatnost">Ne upisuj ovde ime, adresu ni telefon.</p>';

    /** @param  array<string, mixed>  $podaci */
    private function forma(array $podaci): string
    {
        $sablon = Sablon::factory()->objavljen()->create(array_intersect_key($podaci, array_flip(['naslov', 'slug', 'objasnjenje', 'polja', 'tekst'])));

        return (string) $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent();
    }

    // Deo strane po polju, istim redom kao polja šablona: od početka polja do početka sledećeg (poslednji ide do kraja strane).
    /** @return list<string> */
    private function delovi(string $forma): array
    {
        return array_slice(explode('<div class="polje">', $forma), 1);
    }

    /** @return list<array<string, mixed>> */
    private static function sviSabloni(): array
    {
        return [...SabloniSeeder::sabloni(), ...VodiciPrimeriSeeder::sabloni()];
    }

    #[Test]
    public function recenica_stoji_jednom_odmah_ispod_svakog_dugog_polja(): void
    {
        $pregledano = 0;
        $dugih = [];

        foreach (self::sviSabloni() as $podaci) {
            $forma = $this->forma($podaci);
            $delovi = $this->delovi($forma);
            $this->assertCount(count($podaci['polja']), $delovi, $podaci['slug']);
            $broj = 0;

            foreach ($podaci['polja'] as $i => $polje) {
                if (! $polje['dugo']) {
                    continue;
                }

                $broj++;
                $dugih[] = $podaci['slug'].'.'.$polje['kljuc'];
                $this->assertSame(1, substr_count($delovi[$i], self::NAPOMENA), $podaci['slug'].'.'.$polje['kljuc']);
                $this->assertTrue(strpos($delovi[$i], 'id="polje-'.$polje['kljuc'].'"') < strpos($delovi[$i], self::NAPOMENA), $podaci['slug'].'.'.$polje['kljuc'].': rečenica stoji iznad polja');
            }

            $this->assertSame($broj, substr_count($forma, self::NAPOMENA), $podaci['slug']);
            $pregledano++;
        }

        // Neprazan skup: dva šablona iz seedera i šest Ivanovih; duga polja su i „tekst" i polja Ivanovih šablona.
        $this->assertSame(8, $pregledano);
        $this->assertContains('molba.tekst', $dugih);
        $this->assertContains('zahtev.prilozi', $dugih);
        $this->assertGreaterThan(4, count(array_filter($dugih, fn (string $polje) => ! str_ends_with($polje, '.tekst'))));
    }

    // Ogledalo: ispod kratkog polja rečenice nema, a nema je ni na strani pomoćnika.
    #[Test]
    public function ispod_kratkog_polja_recenice_nema(): void
    {
        $kratkih = 0;

        foreach (self::sviSabloni() as $podaci) {
            $delovi = $this->delovi($this->forma($podaci));

            foreach ($podaci['polja'] as $i => $polje) {
                if (! $polje['dugo']) {
                    $kratkih++;
                    $this->assertStringNotContainsString('Ne upisuj ovde ime', $delovi[$i], $podaci['slug'].'.'.$polje['kljuc']);
                }
            }
        }

        $this->assertGreaterThan(20, $kratkih);
        $this->get(route('pomocnik.index'))->assertOk()->assertDontSee('Ne upisuj ovde ime');
    }

    // Pravilo iz 101/T2 ostaje: polje „tekst" nosi rečenicu i kad u šablonu nije označeno kao dugo.
    #[Test]
    public function polje_tekst_nosi_recenicu_i_kad_nije_dugo(): void
    {
        $forma = $this->forma(['naslov' => 'Kratka molba', 'slug' => 'kratka-molba', 'objasnjenje' => 'Probni šablon.', 'tekst' => '{tekst}', 'polja' => [
            ['kljuc' => 'naslov', 'oznaka' => 'Naslov', 'objasnjenje' => '', 'dugo' => false],
            ['kljuc' => 'tekst', 'oznaka' => 'Šta moliš', 'objasnjenje' => '', 'dugo' => false],
        ]]);
        $delovi = $this->delovi($forma);

        $this->assertSame(1, substr_count($forma, self::NAPOMENA));
        $this->assertStringContainsString(self::NAPOMENA, $delovi[1]);
        $this->assertStringNotContainsString(self::NAPOMENA, $delovi[0]);
    }
}
