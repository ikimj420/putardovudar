<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\AdresaNijeJavna;
use App\Services\JavnoPreuzimanje;
use App\Services\StranaNedostupna;
use App\Services\UvozRss;
use App\Services\UvozSpiska;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 95/T1 (PITANJA-92 1): obrada, provera i oba uvoza preuzimaju samo sa javnih adresa, i prvu i onu posle preusmeravanja; nacrt dobija razlog „adresa izvora nije javna".
#[Group('baza')]
class PreuzimanjeSamoSaJavnihAdresaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const RAZLOG = 'adresa izvora nije javna';

    protected function setUp(): void
    {
        parent::setUp();

        // Što nije lažirano, baca izuzetak; zahtevi koje test lažira se broje, a petlja i privatna mreža ne smeju da ih dobiju.
        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function nacrt(string $link, array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...[
            'naslov' => 'Konkurs za stipendije', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => $link,
        ], ...$dopuna]);
    }

    private function preuzmi(string $adresa): mixed
    {
        return $this->app->make(JavnoPreuzimanje::class)->preuzmi($adresa);
    }

    /** @return list<string> adrese svih zahteva koji su stigli do lažne mreže, redom */
    private function poslato(): array
    {
        return Http::recorded()->map(fn (array $par) => $par[0]->url())->values()->all();
    }

    /** Lanac preusmeravanja /p/0 → /p/1 → … → /p/$duzina, koji se na kraju završava odgovorom 200. */
    private function lanacPreusmeravanja(int $duzina): void
    {
        Http::fake(function (Request $zahtev) use ($duzina) {
            $broj = (int) basename((string) parse_url($zahtev->url(), PHP_URL_PATH));

            return $broj < $duzina ? Http::response('', 302, ['Location' => '/p/'.($broj + 1)]) : Http::response('stigla');
        });
    }

    #[Test]
    public function nacrt_sa_adresom_koja_nije_javna_ostaje_nacrt_sa_razlogom_a_nista_se_ne_preuzima(): void
    {
        $nacrt = $this->nacrt('http://127.0.0.1:8080/poziv');
        Http::fake(['*' => Http::response('<html><body><p>strana</p></body></html>')]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('ostaje nacrt ('.self::RAZLOG.'): Konkurs za stipendije')
            ->expectsOutputToContain('nacrt zbog „'.self::RAZLOG.'": 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $nacrt = $nacrt->fresh();
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->status);
        $this->assertNotNull($nacrt->obradeno_at);
        $this->assertSame(self::RAZLOG, data_get($nacrt->predlog, 'odbijeno'));
        $this->assertContains(self::RAZLOG, Prilika::razloziNacrta());

        // Odluka, ne kvar: sutra se ne pokušava iznova.
        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.')->assertSuccessful();
        Http::assertNothingSent();
    }

    // Ogledalo: strana koja samo ne odgovara (javna adresa, HTTP 404) ne upisuje ništa i pokušava se sledećeg dana.
    #[Test]
    public function strana_koja_ne_odgovara_se_ne_upisuje_i_pokusava_se_ponovo(): void
    {
        $nacrt = $this->nacrt('https://a.test/oglas');
        Http::fake(['https://a.test/oglas' => Http::response('', 404)]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('nije obrađen (strana je odgovorila sa HTTP 404)')->assertSuccessful();
        $this->artisan('uvoz:obradi')->expectsOutputToContain('nije obrađen (strana je odgovorila sa HTTP 404)')->assertSuccessful();

        $this->assertNull($nacrt->fresh()->obradeno_at);
        $this->assertNull($nacrt->fresh()->predlog);
        $this->assertSame(['https://a.test/oglas', 'https://a.test/oglas'], $this->poslato());
    }

    #[Test]
    public function preusmeravanje_na_petlju_ili_privatnu_mrezu_se_odbija_pre_druge_veze(): void
    {
        $this->razresivac()->postavi('interno.test', ['10.0.0.5']);

        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data/', 'https://interno.test/x', 'file:///etc/passwd'] as $odrediste) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['https://a.test/poziv' => Http::response('', 302, ['Location' => $odrediste])]);

            try {
                $this->preuzmi('https://a.test/poziv');
                $this->fail('Preusmeravanje na '.$odrediste.' je prošlo.');
            } catch (AdresaNijeJavna $izuzetak) {
                $this->assertSame(self::RAZLOG, $izuzetak->getMessage());
            }

            $this->assertSame(['https://a.test/poziv'], $this->poslato(), $odrediste);
        }
    }

    // Ogledalo: preusmeravanje na javnu adresu se prati, i apsolutno i relativno, a krajnji odgovor se vraća.
    #[Test]
    public function preusmeravanje_na_javnu_adresu_se_prati(): void
    {
        Http::fake([
            'https://a.test/stara' => Http::response('', 301, ['Location' => 'https://b.test/nova']),
            'https://b.test/nova' => Http::response('', 302, ['Location' => '/konacna?x=1']),
            'https://b.test/konacna?x=1' => Http::response('stigla'),
        ]);

        $odgovor = $this->preuzmi('https://a.test/stara');

        $this->assertSame('stigla', $odgovor->body());
        $this->assertSame(['https://a.test/stara', 'https://b.test/nova', 'https://b.test/konacna?x=1'], $this->poslato());
    }

    #[Test]
    public function preusmeravanja_ima_najvise_koliko_piše_u_podesavanju(): void
    {
        $this->assertSame(5, config('uvoz.najvise_preusmeravanja'));

        // Pet preusmeravanja prolazi (šest zahteva), šesto ne.
        $this->lanacPreusmeravanja(5);
        $this->assertSame('stigla', $this->preuzmi('https://a.test/p/0')->body());
        $this->assertCount(6, $this->poslato());

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->lanacPreusmeravanja(6);

        try {
            $this->preuzmi('https://a.test/p/0');
            $this->fail('Šesto preusmeravanje je prošlo.');
        } catch (StranaNedostupna $izuzetak) {
            $this->assertSame('strana ima previše preusmeravanja', $izuzetak->getMessage());
            $this->assertNotInstanceOf(AdresaNijeJavna::class, $izuzetak);
        }

        $this->assertCount(6, $this->poslato());

        // Podešavanje je ono što važi: sa jednim dozvoljenim preusmeravanjem lanac od dva staje.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config()->set('uvoz.najvise_preusmeravanja', 1);
        $this->lanacPreusmeravanja(2);
        $this->expectException(StranaNedostupna::class);
        $this->preuzmi('https://a.test/p/0');
    }

    #[Test]
    public function preusmeravanje_bez_adrese_se_vraca_kao_odgovor(): void
    {
        Http::fake(['https://a.test/poziv' => Http::response('', 302)]);

        $this->assertSame(302, $this->preuzmi('https://a.test/poziv')->status());
        $this->assertSame(['https://a.test/poziv'], $this->poslato());
    }

    #[Test]
    public function prvi_zahtev_ka_adresi_koja_nije_javna_se_ne_salje(): void
    {
        Http::fake(['*' => Http::response('x')]);

        foreach (['http://127.0.0.1/', 'http://10.0.0.1/', 'http://localhost:11434/api/chat', 'ftp://a.test/x'] as $adresa) {
            try {
                $this->preuzmi($adresa);
                $this->fail($adresa.' je prošla.');
            } catch (AdresaNijeJavna) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function uvoz_rss_i_spiskova_odbija_adresu_koja_nije_javna_i_preusmeravanje_na_nju(): void
    {
        Http::fake([
            'https://a.test/feed' => Http::response('', 302, ['Location' => 'http://127.0.0.1/feed']),
            'https://a.test/spisak' => Http::response('', 302, ['Location' => 'http://10.0.0.1/spisak']),
        ]);

        $this->assertSame(self::RAZLOG, $this->app->make(UvozRss::class)->uvezi('Izvor A', 'http://127.0.0.1/feed')->greska);
        $this->assertSame(self::RAZLOG, $this->app->make(UvozRss::class)->uvezi('Izvor A', 'https://a.test/feed')->greska);
        $this->assertSame(self::RAZLOG, $this->app->make(UvozSpiska::class)->uvezi('Spisak', 'http://10.0.0.1/spisak')->greska);
        $this->assertSame(self::RAZLOG, $this->app->make(UvozSpiska::class)->uvezi('Spisak', 'https://a.test/spisak')->greska);
        $this->assertSame(['https://a.test/feed', 'https://a.test/spisak'], $this->poslato());
    }

    // Ogledalo: javan feed se čita kao i pre (RSS sa jednom stavkom).
    #[Test]
    public function javan_feed_se_uvozi_kao_i_pre(): void
    {
        Http::fake(['https://a.test/feed' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>A</title><item><title>Poziv</title><link>https://a.test/poziv-1</link></item></channel></rss>', 200)]);

        $rezultat = $this->app->make(UvozRss::class)->uvezi('Izvor A', 'https://a.test/feed', 1);

        $this->assertNull($rezultat->greska);
        $this->assertSame(1, $rezultat->novih);
    }

    // Dnevna provera adresu koja nije javna ne tumači kao „strane nema": prilika ostaje objavljena, a strana se ne dira.
    #[Test]
    public function provera_ne_vraca_u_nacrt_priliku_ciji_link_nije_javan_i_ne_salje_zahtev(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-11-15', 'link_izvora' => 'http://192.168.0.1/poziv']);
        Http::fake(['*' => Http::response('x')]);

        $this->artisan('prilike:provera')->expectsOutputToContain('Nije provereno (strana nije stigla ili je prvi put nema): 1.')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
    }
}
