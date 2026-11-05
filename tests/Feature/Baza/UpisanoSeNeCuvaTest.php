<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Services\Pomocnik\Pomocnik;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as ZahtevSajta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\BazaTestCase;
use Tests\Concerns\BojeVrata;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;
use Tests\Podrska\LazniClaude;
use Throwable;
use TypeError;

// Paket 64/T7 (Ivan, 11.10.2026.): ono što čovek upiše u „Napiši moj" i u pitanje pomoćniku ne čuvamo i ne koristimo ni za šta drugo. Zahtev nosi poseban trag
// i posle njega ga nema ni u jednoj tabeli (sesija i keš su u bazi), ni u sesiji, kešu, logu ni u adresama strane, ni kad model baci neočekivanu grešku.
#[Group('baza')]
class UpisanoSeNeCuvaTest extends BazaTestCase
{
    use BojeVrata, RefreshDatabase, StilJavnihStrana, VidljivTekst;

    private const TRAG = 'TRAG-7f3a';

    private const MODEL = LazniClaude::ADRESA;

    // Paket 100/T4: natpis kaže i ko piše odgovor, sa vezom na stranu „Privatnost"; tekst je predlog iz paketa, Ivan ga odobrava (PITANJA-100.md).
    private const NATPIS_TEKST = 'Ono što upišeš ne čuvamo. Odgovor piše Claude (Anthropic), detalji su na strani Privatnost.';

    // Prazno dok setUp ne stigne do njega: bez servera baze test se preskače pre toga, a tearDown ipak radi.
    private string $dnevnik = '';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
        // Podešavanja kao na radnom okruženju: sesija i keš (brojač „pitanja u minuti") su u bazi, a log je u fajlu; testovi inače koriste niz.
        config()->set('session.driver', 'database');
        config()->set('cache.default', 'database');
        $this->dnevnik = storage_path('logs/upisano-se-ne-cuva-'.uniqid().'.log');
        config()->set('logging.default', 'single');
        config()->set('logging.channels.single.path', $this->dnevnik);
        // Brojač „pitanja u minuti" je kod nas u nizu i u testu ne piše u keš u bazi; kontrolni red dokazuje da pretraga keša u bazi vidi njegov sadržaj.
        Cache::put('kontrola-merila', 'KONTROLA-7c1d', 600);
    }

    protected function tearDown(): void
    {
        if ($this->dnevnik !== '') {
            File::delete($this->dnevnik);
        }

        parent::tearDown();
    }

    private function sablon(): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'iskustvo', 'oznaka' => 'Iskustvo', 'objasnjenje' => null, 'dugo' => true],
            ],
            'tekst' => "Poštovani,\n\n{iskustvo}\n\nS poštovanjem,\n{ime}",
        ]);
    }

    /**
     * Model: 'radi' odgovara tekstom sa tragom, 'ne radi' je nedostupan, a klasa izuzetka baca tu grešku sa tragom u poruci.
     *
     * @param  class-string<Throwable>|string  $ishod
     */
    private function model(string $ishod): void
    {
        Http::fake([self::MODEL => function (Request $zahtev) use ($ishod) {
            return match (true) {
                $ishod === 'radi' => LazniClaude::odgovor('Ana Anić: napisano o '.self::TRAG.'.'),
                $ishod === 'ne radi' => throw new ConnectionException('nema veze'),
                default => throw new $ishod('Pukao na tekstu '.self::TRAG),
            };
        }]);
    }

    /** @return array<string, array{0: string}> */
    public static function ishodi(): array
    {
        return [
            'model odgovara' => ['radi'],
            'model ne radi' => ['ne radi'],
            'neočekivana greška: RuntimeException' => [RuntimeException::class],
            'neočekivana greška: LogicException' => [LogicException::class],
            'neočekivana greška: TypeError' => [TypeError::class],
            'neočekivana greška: JsonException' => [JsonException::class],
        ];
    }

    /** @return array<string, int> broj redova po tabeli, bez sesija i keša (oni se menjaju sami od sebe) */
    private function redovi(): array
    {
        return collect($this->tabele())->reject(fn (string $tabela) => in_array($tabela, ['sessions', 'cache', 'cache_locks'], true))
            ->mapWithKeys(fn (string $tabela) => [$tabela => DB::table($tabela)->count()])->all();
    }

    /** @return list<string> */
    private function tabele(): array
    {
        return array_column(array_filter(Schema::getTables(), fn (array $tabela) => ($tabela['schema'] ?? DB::getDatabaseName()) === DB::getDatabaseName()), 'name');
    }

    // Sav sadržaj baze kao tekst (sesija je u base64, pa se i ona otvara).
    private function sadrzajBaze(): string
    {
        $tekst = '';

        foreach ($this->tabele() as $tabela) {
            foreach (DB::table($tabela)->get() as $red) {
                $niz = (array) $red;
                $tekst .= json_encode($niz, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                $tekst .= isset($niz['payload']) ? base64_decode((string) $niz['payload']) : '';
            }
        }

        return $tekst;
    }

    // Zapisi ovog testa (poseban fajl) i zapisi svih fajlova u storage/logs, i starih.
    private function mojDnevnik(): string
    {
        return is_file($this->dnevnik) ? (string) file_get_contents($this->dnevnik) : '';
    }

    private function dnevnici(): string
    {
        return collect(File::glob(storage_path('logs/*')))->map(fn (string $fajl) => (string) file_get_contents($fajl))->implode("\n");
    }

    // Posle zahteva traga nema nigde; ogledalo: u odgovoru koji vidi sam čovek on jeste.
    private function proveriDaTragaNema(TestResponse $odgovor, array $redoviPre): void
    {
        $html = $odgovor->getContent();

        // Merilo prvo dokazuje da je obišlo neprazan skup: sesija i keš imaju redove, a baza ima tabele i sadržaj.
        $this->assertGreaterThan(0, DB::table('sessions')->count(), 'sesija je upisana u bazu');
        $this->assertStringContainsString('KONTROLA-7c1d', $this->sadrzajBaze(), 'kontrolni red je u kešu u bazi, pa ga merilo vidi');
        $this->assertGreaterThan(10, count($this->tabele()));

        $this->assertStringNotContainsString(self::TRAG, $this->sadrzajBaze(), 'baza (sa sesijom i kešom)');
        $this->assertSame($redoviPre, $this->redovi(), 'nijedna druga tabela nema nov red');
        $this->assertStringNotContainsString(self::TRAG, (string) json_encode(session()->all(), JSON_UNESCAPED_UNICODE), 'sesija');
        $this->assertStringNotContainsString(self::TRAG, $this->dnevnici(), 'log');
        $this->assertNull($odgovor->headers->get('Location'));

        preg_match_all('/(?:href|action|src)="([^"]*)"/i', $html, $adrese);
        $this->assertGreaterThan(5, count($adrese[1]), 'merilo adresa mora da obiđe adrese na strani');

        foreach ($adrese[1] as $adresa) {
            $this->assertStringNotContainsString(self::TRAG, rawurldecode($adresa), 'adresa na strani');
        }

        $this->assertStringContainsString(self::TRAG, $html, 'ogledalo: upisano jeste u strani koju vidi sam čovek');
    }

    // „Napiši moj": polja i odgovor modela nose trag; šta god model uradi, ništa se ne čuva.
    #[Test]
    #[DataProvider('ishodi')]
    public function napisi_moj_ne_cuva_upisano_ni_kad_model_pukne(string $ishod): void
    {
        $sablon = $this->sablon();
        $this->model($ishod);
        $pre = $this->redovi();

        $odgovor = $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['ime' => 'Ana Anić', 'iskustvo' => 'Radila sam '.self::TRAG.'.']])->assertOk();

        $this->proveriDaTragaNema($odgovor, $pre);

        if ($ishod === 'radi') {
            $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $odgovor->getContent());

            return;
        }

        // Kad model ne radi ili pukne, čovek dobija šablon sklopljen iz svojih odgovora, a ne grešku.
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $odgovor->getContent());
        $this->assertStringContainsString("Poštovani,\n\nRadila sam ".self::TRAG.".\n\nS poštovanjem,\nAna Anić", html_entity_decode($odgovor->getContent()));

        if ($ishod === 'ne radi') {
            $this->assertSame('', $this->mojDnevnik(), 'model koji ne radi nije greška koju treba zapisati');

            return;
        }

        // Neočekivana greška je u logu samo kao vrsta; ogledalo: log je dobio zapis.
        $this->assertStringContainsString($ishod, $this->mojDnevnik());
    }

    private function prilika(): void
    {
        Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'opis' => 'Rad u skladištu.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
    }

    /**
     * Prvi poziv (format json) daje formular, drugi tekst; 'prvi' i 'drugi' bacaju grešku u tom pozivu.
     *
     * @param  class-string<Throwable>|string  $ishod
     */
    private function modelPomocnika(string $ishod, string $gde = 'drugi'): void
    {
        Http::fake([self::MODEL => function (Request $zahtev) use ($ishod, $gde) {
            $prvi = LazniClaude::jeJson($zahtev);

            return match (true) {
                $ishod === 'ne radi' && ($gde === 'prvi') === $prvi => throw new ConnectionException('nema veze'),
                $ishod !== 'radi' && $ishod !== 'ne radi' && ($gde === 'prvi') === $prvi => throw new $ishod('Pukao na pitanju '.self::TRAG),
                $prvi => LazniClaude::odgovor(json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])),
                default => LazniClaude::odgovor('U Nišu postoji posao radnika u skladištu, rok je 15. novembra 2026. '.self::TRAG),
            };
        }]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function ishodiPitanja(): array
    {
        return [
            'oba poziva rade' => ['radi', 'drugi'],
            'prvi poziv ne radi' => ['ne radi', 'prvi'],
            'drugi poziv ne radi' => ['ne radi', 'drugi'],
            'prvi poziv: RuntimeException' => [RuntimeException::class, 'prvi'],
            'prvi poziv: TypeError' => [TypeError::class, 'prvi'],
            'drugi poziv: RuntimeException' => [RuntimeException::class, 'drugi'],
            'drugi poziv: LogicException' => [LogicException::class, 'drugi'],
            'drugi poziv: TypeError' => [TypeError::class, 'drugi'],
        ];
    }

    // Pitanje pomoćniku: nosi trag i u pitanju i u odgovoru modela; ništa se ne čuva, a čovek nikad ne dobija 500.
    #[Test]
    #[DataProvider('ishodiPitanja')]
    public function pitanje_pomocniku_se_ne_cuva_ni_kad_model_pukne(string $ishod, string $gde): void
    {
        $this->prilika();
        $this->modelPomocnika($ishod, $gde);
        $pre = $this->redovi();

        $odgovor = $this->post(route('pomocnik.pitaj'), ['pitanje' => self::TRAG.' Ima li posla u Nišu?'])->assertOk();

        $this->proveriDaTragaNema($odgovor, $pre);
        $tekst = $this->vidljivTekst($odgovor->getContent());

        match (true) {
            $ishod === 'radi' => $this->assertStringContainsString('U Nišu postoji posao radnika u skladištu', $tekst),
            $gde === 'prvi' => $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $tekst),
            default => $this->assertStringContainsString(Pomocnik::SABLON_NASLOV, $tekst),
        };

        if ($ishod === 'radi' || $ishod === 'ne radi') {
            $this->assertSame('', $this->mojDnevnik(), 'ni uspeh ni nedostupan model ne pišu u log');

            return;
        }

        // Neočekivana greška je u logu samo kao vrsta; ogledalo: log je dobio zapis.
        $this->assertStringContainsString($ishod, $this->mojDnevnik());
    }

    // Prozor pomoćnika traži isto što i strana: ni on ne ostavlja trag.
    #[Test]
    public function pitanje_iz_prozora_se_ne_cuva_ni_kad_model_pukne(): void
    {
        $this->prilika();
        $this->modelPomocnika(RuntimeException::class, 'prvi');
        $pre = $this->redovi();

        $odgovor = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post(route('pomocnik.pitaj'), ['pitanje' => self::TRAG.' Ima li posla u Nišu?']);

        $odgovor->assertOk();
        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($odgovor->getContent()));
        $this->assertStringNotContainsString(self::TRAG, $this->sadrzajBaze());
        $this->assertSame($pre, $this->redovi());
        $this->assertStringNotContainsString(self::TRAG, $this->dnevnici());
        $this->assertStringContainsString(RuntimeException::class, $this->mojDnevnik());
    }

    // Brojač „pitanja u minuti" se vodi samo po adresi posetioca; ono što je upisano u telu zahteva u njegov ključ ne ulazi.
    #[Test]
    public function brojac_pitanja_u_minuti_se_vodi_samo_po_adresi(): void
    {
        $ogranicenje = RateLimiter::limiter('pomocnik');
        $zahtev = fn (string $adresa) => ZahtevSajta::create('/pomocnik', 'POST', ['pitanje' => 'Ima li posla? '.self::TRAG, 'polje' => ['ime' => self::TRAG]], server: ['REMOTE_ADDR' => $adresa]);

        $this->assertNotNull($ogranicenje);
        $this->assertSame('10.1.2.3', $ogranicenje($zahtev('10.1.2.3'))->key);
        $this->assertSame('10.9.8.7', $ogranicenje($zahtev('10.9.8.7'))->key, 'ogledalo: druga adresa daje drugi ključ');
    }

    // Natpis je Ivanov tekst (11.10.2026.), ne konstanta koja se poredi sama sa sobom.
    #[Test]
    public function natpis_je_ispod_dugmeta_napisi_ispod_polja_za_pitanje_i_u_prozoru(): void
    {
        $this->assertSame(self::NATPIS_TEKST, PrikazPomocnika::NE_CUVAMO);
        $natpis = '<p class="privatnost">Ono što upišeš ne čuvamo. Odgovor piše Claude (Anthropic), detalji su na strani <a href="'.route('privatnost').'">Privatnost</a>.</p>';
        $sablon = $this->sablon();

        // Strana pomoćnika: odmah ispod polja za pitanje; prozora na njoj nema, pa je natpis jedan.
        $strana = $this->get(route('pomocnik.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#</textarea>\s*'.preg_quote($natpis, '#').'#', $strana);
        $this->assertSame(1, substr_count($strana, $natpis));

        // „Napiši moj": ispod dugmeta „Napiši tekst", u obrascu; ni na ovoj strani prozora nema.
        $napisi = $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<button type="submit">'.preg_quote(PrikazPomocnika::NAPISI, '#').'</button>\s*'.preg_quote($natpis, '#').'\s*</form>#', $napisi);
        $this->assertSame(1, substr_count($napisi, $natpis));

        // Prozor je na svakoj javnoj strani: natpis je ispod njegovog polja i dugmeta.
        $pocetna = $this->get(route('pocetna'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<button type="submit">'.preg_quote(PrikazPomocnika::DUGME, '#').'</button>\s*'.preg_quote($natpis, '#').'\s*</form>#', $pocetna);
        $this->assertSame(1, substr_count($pocetna, $natpis));
    }

    // Linkovi nisu podvučeni (paket 66, Ivan, 11.10.2026.): veza u natpisu se razlikuje bojom i debljinom slova, a boja ima kontrast sa belom podlogom (paket 100/T4).
    #[Test]
    public function veza_u_natpisu_se_razlikuje_od_teksta_bojom_i_debljinom(): void
    {
        $stil = $this->stilBezKomentara();
        $kontrast = fn (string $a, string $b): float => (max($this->osvetljenost($a), $this->osvetljenost($b)) + 0.05) / (min($this->osvetljenost($a), $this->osvetljenost($b)) + 0.05);

        $this->assertMatchesRegularExpression('/\n\.privatnost a \{\s*color: var\(--ljubicasta\);\s*font-weight: 700;\s*\}/', $stil);
        $this->assertMatchesRegularExpression('/\n\.privatnost a:hover \{\s*color: var\(--ljubicasta-svetla\);\s*\}/', $stil);
        $this->assertDoesNotMatchRegularExpression('/\.privatnost a[^{]*\{[^}]*text-decoration: underline/', $stil);

        preg_match_all('/--(ljubicasta|ljubicasta-svetla|card-bg|zlato): (#[0-9a-f]{6});/', $stil, $nalazi, PREG_SET_ORDER);
        $boje = array_column($nalazi, 2, 1);
        $this->assertCount(4, $boje);

        foreach (['ljubicasta', 'ljubicasta-svetla'] as $ime) {
            $this->assertGreaterThanOrEqual(4.5, $kontrast($boje[$ime], $boje['card-bg']), $ime);
        }

        // Ogledalo: merilo stvarno razlikuje; svetla boja (zlato) na beloj nema kontrast 4,5.
        $this->assertLessThan(4.5, $kontrast($boje['zlato'], $boje['card-bg']));
    }

    #[Test]
    public function natpis_je_mali_i_u_prozoru_ima_svoj_red(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.privatnost \{\s*color: var\(--muted\);\s*font-size: 13px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-forma \.privatnost \{\s*flex: 1 0 100%;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-forma \{[^}]*flex-wrap: wrap;/', $stil);
    }
}
