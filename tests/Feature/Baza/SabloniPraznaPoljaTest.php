<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use Database\Seeders\VodiciPrimeriSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 72/T4 (Ivan, PITANJA-70, 1): jednokratna komanda upisuje pravila praznih polja iz paketa 70/T10 šablonima koji već postoje, samo gde to podešavanje nije zadato.
#[Group('baza')]
class SabloniPraznaPoljaTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
    }

    /**
     * @param  list<array<string, mixed>>  $polja
     * @param  array<string, mixed>  $dopuna
     */
    private function sablon(string $slug, array $polja, array $dopuna = []): Sablon
    {
        $sirova = array_map(fn (array $polje) => ['kljuc' => $polje['kljuc'], 'oznaka' => 'Natpis '.$polje['kljuc'], 'objasnjenje' => 'Objašnjenje '.$polje['kljuc'], 'dugo' => false, ...$polje], $polja);

        return Sablon::factory()->create([...[
            'slug' => $slug, 'naslov' => 'Šablon '.$slug, 'polja' => $sirova,
            'tekst' => implode("\n\n", array_map(fn (array $polje) => '{'.$polje['kljuc'].'}', $sirova)),
        ], ...$dopuna]);
    }

    /** Šabloni kakvi su u radnoj bazi: ključevi su tu, pravila nema. */
    private function radnaBaza(): void
    {
        $this->sablon('cv', [['kljuc' => 'ime'], ['kljuc' => 'kursevi'], ['kljuc' => 'preporuke']]);
        $this->sablon('motivaciono-pismo', [['kljuc' => 'kome'], ['kljuc' => 'skola']]);
        $this->sablon('moje-predstavljanje', [['kljuc' => 'ime'], ['kljuc' => 'skola']]);
        // Isti ključ „kome" u šablonu kome paket ne zadaje pravilo.
        $this->sablon('molba-za-preporuku', [['kljuc' => 'kome']]);
    }

    /** @return array<string, mixed> polje šablona po ključu */
    private function polje(string $slug, string $kljuc): array
    {
        foreach ((array) Sablon::query()->where('slug', $slug)->sole()->polja as $polje) {
            if ($polje['kljuc'] === $kljuc) {
                return $polje;
            }
        }

        $this->fail('Nema polja '.$kljuc.' u šablonu '.$slug);
    }

    #[Test]
    public function prazna_podesavanja_se_popunjavaju_i_ispisuje_se_sta_je_upisano(): void
    {
        $this->radnaBaza();

        $this->artisan('sabloni:prazna-polja')
            ->expectsOutput('cv · kursevi: red se ne vidi kad je prazno.')
            ->expectsOutput('cv · preporuke: kad je prazno, piše „Preporuke dostupne na zahtev".')
            ->expectsOutput('motivaciono-pismo · kome: kad je prazno, piše „Poštovani".')
            ->expectsOutput('moje-predstavljanje · skola: red se ne vidi kad je prazno.')
            ->expectsOutput('Upisano: 4. Preskočeno: 0.')
            ->assertExitCode(0);

        $this->assertTrue($this->polje('cv', 'kursevi')['opciono']);
        $this->assertSame('Preporuke dostupne na zahtev', $this->polje('cv', 'preporuke')['ako_prazno']);
        $this->assertSame('Poštovani', $this->polje('motivaciono-pismo', 'kome')['ako_prazno']);
        $this->assertTrue($this->polje('moje-predstavljanje', 'skola')['opciono']);
    }

    // Ogledalo: ostala polja, drugi šabloni i sve ostalo na šablonu ostaje kako je bilo; „kome" u molbi i „skola" u motivacionom pismu nemaju pravilo.
    #[Test]
    public function sve_ostalo_ostaje_kako_je_bilo(): void
    {
        $this->radnaBaza();
        $pre = Sablon::query()->orderBy('id')->get()->map(fn (Sablon $sablon) => $sablon->getAttributes())->all();

        $this->artisan('sabloni:prazna-polja')->assertExitCode(0);

        $this->assertArrayNotHasKey('ako_prazno', $this->polje('molba-za-preporuku', 'kome'));
        $this->assertArrayNotHasKey('opciono', $this->polje('molba-za-preporuku', 'kome'));
        $this->assertArrayNotHasKey('ako_prazno', $this->polje('motivaciono-pismo', 'skola'));
        $this->assertArrayNotHasKey('opciono', $this->polje('motivaciono-pismo', 'skola'));
        $this->assertArrayNotHasKey('ako_prazno', $this->polje('cv', 'ime'));
        $this->assertSame('Natpis kursevi', $this->polje('cv', 'kursevi')['oznaka']);
        $this->assertSame('Objašnjenje preporuke', $this->polje('cv', 'preporuke')['objasnjenje']);
        $this->assertSame(['ime', 'kursevi', 'preporuke'], Sablon::query()->where('slug', 'cv')->sole()->kljucevi());

        foreach (Sablon::query()->orderBy('id')->get() as $i => $posle) {
            foreach (['naslov', 'slug', 'objasnjenje', 'tekst', 'status'] as $kolona) {
                $this->assertSame($pre[$i][$kolona], $posle->getAttributes()[$kolona], $posle->slug.' '.$kolona);
            }
        }

        // Šablon koji nema nijedno pravilo se uopšte ne snima: ni vreme izmene mu se ne pomera.
        $this->assertSame($pre[3]['updated_at'], Sablon::query()->where('slug', 'molba-za-preporuku')->sole()->getAttributes()['updated_at']);
    }

    // Ono što je Ivan podesio u adminu se ne menja, i ne pravi se podešavanje koje forma ne dozvoljava (reč i izostavljen red zajedno).
    #[Test]
    public function podeseno_u_adminu_ostaje_i_ne_pravi_se_protivrecno_podesavanje(): void
    {
        $this->sablon('cv', [['kljuc' => 'kursevi', 'ako_prazno' => 'Nema kurseva'], ['kljuc' => 'preporuke', 'opciono' => true]]);
        $this->sablon('motivaciono-pismo', [['kljuc' => 'kome', 'ako_prazno' => 'Dragi']]);
        $this->sablon('moje-predstavljanje', [['kljuc' => 'skola', 'opciono' => true]]);

        $this->artisan('sabloni:prazna-polja')
            ->expectsOutput('cv · kursevi: preskočeno, polje već ima reč za prazno polje.')
            ->expectsOutput('cv · preporuke: preskočeno, red je podešen da se ne vidi.')
            ->expectsOutput('motivaciono-pismo · kome: preskočeno, već je podešeno.')
            ->expectsOutput('moje-predstavljanje · skola: preskočeno, već je podešeno.')
            ->expectsOutput('Upisano: 0. Preskočeno: 4.')
            ->assertExitCode(0);

        $this->assertSame('Nema kurseva', $this->polje('cv', 'kursevi')['ako_prazno']);
        $this->assertArrayNotHasKey('opciono', $this->polje('cv', 'kursevi'));
        $this->assertTrue($this->polje('cv', 'preporuke')['opciono']);
        $this->assertArrayNotHasKey('ako_prazno', $this->polje('cv', 'preporuke'));
        $this->assertSame('Dragi', $this->polje('motivaciono-pismo', 'kome')['ako_prazno']);
        $this->assertTrue($this->polje('moje-predstavljanje', 'skola')['opciono']);
    }

    // Admin pri snimanju upisuje prazne vrednosti („" i false): to je isto što i nepodešeno.
    #[Test]
    public function prazno_koje_upisuje_admin_se_racuna_kao_nepodeseno(): void
    {
        $this->sablon('cv', [['kljuc' => 'kursevi', 'ako_prazno' => '', 'opciono' => false], ['kljuc' => 'preporuke', 'ako_prazno' => null, 'opciono' => false]]);

        $this->artisan('sabloni:prazna-polja')->assertExitCode(0);

        $this->assertTrue($this->polje('cv', 'kursevi')['opciono']);
        $this->assertSame('', $this->polje('cv', 'kursevi')['ako_prazno']);
        $this->assertSame('Preporuke dostupne na zahtev', $this->polje('cv', 'preporuke')['ako_prazno']);
        $this->assertFalse($this->polje('cv', 'preporuke')['opciono']);
    }

    #[Test]
    public function drugo_pokretanje_ne_menja_nista(): void
    {
        $this->radnaBaza();
        $this->artisan('sabloni:prazna-polja')->assertExitCode(0);
        $posle = Sablon::query()->orderBy('id')->get()->map(fn (Sablon $sablon) => $sablon->getAttributes())->all();

        $this->travel(2)->hours();

        $this->artisan('sabloni:prazna-polja')
            ->expectsOutput('Upisano: 0. Preskočeno: 4.')
            ->assertExitCode(0);

        $this->assertSame($posle, Sablon::query()->orderBy('id')->get()->map(fn (Sablon $sablon) => $sablon->getAttributes())->all());
    }

    // Objavljen šablon ostaje objavljen, a nacrt nacrt.
    #[Test]
    public function status_sablona_se_ne_menja(): void
    {
        $this->sablon('cv', [['kljuc' => 'kursevi'], ['kljuc' => 'preporuke']], ['status' => StatusObjave::Objavljeno]);
        $this->sablon('motivaciono-pismo', [['kljuc' => 'kome']], ['status' => StatusObjave::Nacrt]);

        $this->artisan('sabloni:prazna-polja')->assertExitCode(0);

        $this->assertSame(StatusObjave::Objavljeno, Sablon::query()->where('slug', 'cv')->sole()->status);
        $this->assertSame(StatusObjave::Nacrt, Sablon::query()->where('slug', 'motivaciono-pismo')->sole()->status);
        $this->assertSame('Poštovani', $this->polje('motivaciono-pismo', 'kome')['ako_prazno']);
    }

    // Šablon koga nema se ne pravi, a polje koga nema se ne dodaje; ostala pravila se i dalje upisuju.
    #[Test]
    public function sablon_ili_polje_kog_nema_se_ne_pravi_a_ostalo_se_upisuje(): void
    {
        $this->sablon('cv', [['kljuc' => 'preporuke']]);

        $this->artisan('sabloni:prazna-polja')
            ->expectsOutput('cv · kursevi: preskočeno, polja nema.')
            ->expectsOutput('cv · preporuke: kad je prazno, piše „Preporuke dostupne na zahtev".')
            ->expectsOutput('motivaciono-pismo: preskočeno, šablona nema.')
            ->expectsOutput('moje-predstavljanje: preskočeno, šablona nema.')
            ->expectsOutput('Upisano: 1. Preskočeno: 3.')
            ->assertExitCode(0);

        $this->assertSame(1, Sablon::query()->count());
        $this->assertSame(['preporuke'], Sablon::query()->sole()->kljucevi());
    }

    // Šablon koji se ne može snimiti (objavljen, a tekst ima mesto za polje kog nema) ne obara ostale.
    #[Test]
    public function sablon_koji_se_ne_moze_snimiti_ne_obara_ostale(): void
    {
        Sablon::withoutEvents(fn () => $this->sablon('cv', [['kljuc' => 'kursevi'], ['kljuc' => 'preporuke']], ['status' => StatusObjave::Objavljeno, 'tekst' => '{kursevi} {preporuke} {nepostojece}']));
        $this->sablon('motivaciono-pismo', [['kljuc' => 'kome']]);

        $this->artisan('sabloni:prazna-polja')
            ->expectsOutputToContain('cv: nije upisano (Tekst ima mesto za polje koje ne postoji: {nepostojece})')
            ->expectsOutput('motivaciono-pismo · kome: kad je prazno, piše „Poštovani".')
            ->assertExitCode(0);

        $this->assertArrayNotHasKey('opciono', $this->polje('cv', 'kursevi'));
        $this->assertSame('Poštovani', $this->polje('motivaciono-pismo', 'kome')['ako_prazno']);
    }

    // Pravila iz komande su ista kao ona koja seeder zadaje novim šablonima (isti dokument, VODICI-PRIMERI.md).
    #[Test]
    public function komanda_daje_isto_sto_i_seeder_zadaje_novim_sablonima(): void
    {
        (new VodiciPrimeriSeeder)->run();
        $kljucevi = ['cv' => ['kursevi', 'preporuke'], 'motivaciono-pismo' => ['kome'], 'moje-predstavljanje' => ['skola']];
        $seeder = [];

        foreach ($kljucevi as $slug => $kljuceviSablona) {
            foreach ($kljuceviSablona as $kljuc) {
                $polje = $this->polje($slug, $kljuc);
                $seeder[$slug.'.'.$kljuc] = ['ako_prazno' => $polje['ako_prazno'] ?? null, 'opciono' => $polje['opciono'] ?? null];
            }
        }

        // Neprazan skup: seeder zadaje četiri pravila, i to po jedno od oba vida.
        $this->assertCount(4, array_filter($seeder, fn (array $pravilo) => $pravilo['ako_prazno'] !== null || $pravilo['opciono'] !== null));
        $this->assertCount(2, array_filter($seeder, fn (array $pravilo) => $pravilo['ako_prazno'] !== null));
        $this->assertCount(2, array_filter($seeder, fn (array $pravilo) => $pravilo['opciono'] === true));

        foreach (array_keys($kljucevi) as $slug) {
            $sablon = Sablon::query()->where('slug', $slug)->sole();
            $sablon->polja = array_map(fn (array $polje) => array_diff_key($polje, ['ako_prazno' => 1, 'opciono' => 1]), (array) $sablon->polja);
            $sablon->save();
        }

        $this->artisan('sabloni:prazna-polja')->expectsOutput('Upisano: 4. Preskočeno: 0.')->assertExitCode(0);

        foreach ($kljucevi as $slug => $kljuceviSablona) {
            foreach ($kljuceviSablona as $kljuc) {
                $polje = $this->polje($slug, $kljuc);

                $this->assertSame($seeder[$slug.'.'.$kljuc], ['ako_prazno' => $polje['ako_prazno'] ?? null, 'opciono' => $polje['opciono'] ?? null], $slug.'.'.$kljuc);
            }
        }
    }
}
