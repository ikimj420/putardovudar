<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Snimak odgovora je sastavljen po dokumentaciji Jooble-a (tests/Fixtures/jooble/), nije pravi odgovor.
#[Group('baza')]
class UvozJoobleTest extends BazaTestCase
{
    use RefreshDatabase;

    private const KLJUC = 'PROBNI-KLJUC-123';

    private const ADRESA = 'https://jooble.org/api/PROBNI-KLJUC-123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('jooble.kljuc', self::KLJUC);
    }

    private function snimak(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/jooble/odgovor-sastavljen-po-dokumentaciji.json'));
    }

    /** @return array<string, mixed> */
    private function posao(int $broj): array
    {
        return ['id' => $broj, 'title' => "Posao $broj", 'location' => 'Niš', 'snippet' => "Opis $broj", 'link' => "https://rs.jooble.org/desc/$broj"];
    }

    #[Test]
    public function salje_zahtev_po_dokumentaciji_sa_kljucem_u_putanji(): void
    {
        Http::fake([self::ADRESA => Http::response($this->snimak())]);

        $this->artisan('uvoz:jooble')->assertExitCode(0);

        Http::assertSent(fn (Request $zahtev) => $zahtev->method() === 'POST'
            && $zahtev->url() === self::ADRESA
            && $zahtev->data() === ['keywords' => 'posao', 'location' => 'Srbija', 'page' => 1, 'ResultOnPage' => 50]);
    }

    #[Test]
    public function posao_postaje_nacrt_kao_rss_latinicom_bez_oznaka_i_bez_duplikata(): void
    {
        Http::fake([self::ADRESA => Http::response($this->snimak())]);

        $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 2, preskočeno 2, greška: nema')->assertExitCode(0);

        $this->assertSame(2, Prilika::query()->count());

        $prvi = Prilika::query()->where('link_izvora', 'https://rs.jooble.org/desc/1100000000000001')->sole();
        $drugi = Prilika::query()->where('link_izvora', 'https://rs.jooble.org/desc/1100000000000002')->sole();

        $this->assertSame('Radnik u skladištu', $prvi->naslov);
        $this->assertSame(StatusPrilike::Nacrt, $prvi->status);
        $this->assertSame(VrstaPrilike::Drugo, $prvi->vrsta);
        $this->assertNull($prvi->rok);
        $this->assertSame('Jooble', $prvi->naziv_izvora);
        $this->assertSame('Tražimo radnika za rad u skladištu, puno radno vreme i smene & prevoz.', $prvi->kratak_opis);
        $this->assertSame('Prodavac u radnji', $drugi->naslov);
        $this->assertSame('Rad u maloprodaji i na kasi.', $drugi->kratak_opis);
        $this->assertSame(0, preg_match('/\p{Cyrillic}/u', Prilika::query()->get()->toJson(JSON_UNESCAPED_UNICODE)));
    }

    // Ogledalo: drugo pokretanje ne pravi nove nacrte.
    #[Test]
    public function drugo_pokretanje_ne_duplira_nacrte(): void
    {
        Http::fake([self::ADRESA => Http::response($this->snimak())]);

        $this->artisan('uvoz:jooble');
        $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 0, preskočeno 4, greška: nema');

        $this->assertSame(2, Prilika::query()->count());
    }

    #[Test]
    public function uvozi_najvise_pedeset_poslova(): void
    {
        Http::fake([self::ADRESA => Http::response(['totalCount' => 60, 'jobs' => array_map(fn (int $broj) => $this->posao($broj), range(1, 60))])]);

        $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 50, preskočeno 0, greška: nema');

        $this->assertSame(50, Prilika::query()->count());
    }

    #[Test]
    public function bez_kljuca_se_preskace_bez_zahteva_i_bez_greske(): void
    {
        foreach ([null, '', '   '] as $kljuc) {
            config()->set('jooble.kljuc', $kljuc);

            $this->artisan('uvoz:jooble')->expectsOutput('Jooble: preskočeno, JOOBLE_KLJUC nije podešen.')->assertExitCode(0);
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{0: callable(): mixed, 1: string}> */
    public static function greske(): array
    {
        return [
            'ključ nije prihvaćen' => [fn () => Http::response('Access denied', 403), 'Jooble nije prihvatila ključ (HTTP 403)'],
            'greška servera' => [fn () => Http::response('x', 500), 'Jooble je odgovorila sa HTTP 500'],
            'ne odgovara' => [fn () => fn () => throw new ConnectionException('cURL error 28 for https://jooble.org/api/PROBNI-KLJUC-123'), 'Jooble ne odgovara'],
            'odgovor nije u obliku' => [fn () => Http::response(['totalCount' => 1]), 'odgovor nije u očekivanom obliku'],
            'odgovor nije JSON' => [fn () => Http::response('<html>nije json</html>'), 'odgovor nije u očekivanom obliku'],
        ];
    }

    /** @param  callable(): mixed  $odgovor */
    #[Test]
    #[DataProvider('greske')]
    public function greska_se_prijavljuje_izlazom_1_a_kljuc_se_nigde_ne_ispisuje(callable $odgovor, string $poruka): void
    {
        Http::fake([self::ADRESA => $odgovor()]);

        $izlaz = $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 0, preskočeno 0, greška: '.$poruka)->assertExitCode(1);

        $this->assertSame(0, Prilika::query()->count());
        $this->assertStringNotContainsString(self::KLJUC, $poruka);
        unset($izlaz);
    }

    #[Test]
    public function oglas_bez_naslova_ili_sa_pokvarenim_linkom_se_preskace(): void
    {
        Http::fake([self::ADRESA => Http::response(['jobs' => [
            ['title' => '', 'link' => 'https://rs.jooble.org/desc/1'],
            ['title' => 'Bez linka'],
            ['title' => 'Link nije adresa', 'link' => 'ftp://nesto'],
            'nije niz',
            $this->posao(5),
        ]])]);

        $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 1, preskočeno 4, greška: nema');
    }

    #[Test]
    public function oznake_za_lom_reda_postaju_razmak_a_oznake_za_isticanje_ne(): void
    {
        Http::fake([self::ADRESA => Http::response(['jobs' => [['title' => 'Posao', 'snippet' => 'Prvi red<br>drugi <b>red</b>, treći.</p>Četvrti', 'link' => 'https://rs.jooble.org/desc/7']]])]);

        $this->artisan('uvoz:jooble');

        $this->assertSame('Prvi red drugi red, treći. Četvrti', Prilika::query()->sole()->kratak_opis);
    }

    #[Test]
    public function odgovor_veci_od_dozvoljenog_se_odbija(): void
    {
        config()->set('uvoz.najvise_bajtova', 100);
        Http::fake([self::ADRESA => Http::response($this->snimak())]);

        $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 0, preskočeno 0, greška: odgovor je veći od dozvoljene veličine')->assertExitCode(1);
    }

    // Poruka izuzetka može da nosi adresu sa ključem; zato se pri neočekivanoj grešci ne ispisuje.
    #[Test]
    public function neocekivana_greska_pri_upisu_ne_ispisuje_poruku_izuzetka(): void
    {
        Http::fake([self::ADRESA => Http::response($this->snimak())]);
        Prilika::creating(fn () => throw new \RuntimeException('greška za '.self::ADRESA));

        $this->artisan('uvoz:jooble')->expectsOutput('Jooble: novih 0, preskočeno 0, greška: neočekivana greška pri upisu')->assertExitCode(1);
    }

    #[Test]
    public function snimak_je_oznacen_kao_sastavljen_po_dokumentaciji(): void
    {
        $niz = json_decode($this->snimak(), true);

        $this->assertStringContainsString('NIJE PRAVI ODGOVOR', $niz['_napomena']);
        $this->assertStringContainsString('help.jooble.org', $niz['_napomena']);
        $this->assertArrayHasKey('totalCount', $niz);
        $this->assertSame(['id', 'title', 'location', 'snippet', 'salary', 'source', 'type', 'link', 'company', 'updated'], array_keys($niz['jobs'][0]));
    }

    #[Test]
    public function kljuc_dolazi_iz_okruzenja_a_u_primeru_je_samo_prazno_ime(): void
    {
        $primer = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^JOOBLE_KLJUC=$/m', $primer);
        $this->assertDoesNotMatchRegularExpression('/^JOOBLE_KLJUC=.+$/m', $primer);
        $this->assertSame('Jooble', config('jooble.ime'));
    }
}
