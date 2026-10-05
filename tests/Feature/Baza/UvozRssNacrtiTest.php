<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class UvozRssNacrtiTest extends BazaTestCase
{
    use RefreshDatabase;

    private const A = 'https://izvor-a.test/feed/';

    private const B = 'https://izvor-b.test/feed/';

    protected function setUp(): void
    {
        parent::setUp();

        // Nijedan zahtev ne sme da ode na pravi internet: što nije lažirano, baca izuzetak.
        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => self::A]]);
    }

    /** @param  list<array{0: string, 1: string, 2?: string}>  $stavke */
    private function feed(array $stavke): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Probni izvor</title>';

        foreach ($stavke as $stavka) {
            $xml .= '<item><title>'.$stavka[0].'</title><link>'.$stavka[1].'</link>';
            $xml .= isset($stavka[2]) ? '<description><![CDATA['.$stavka[2].']]></description>' : '';
            $xml .= '</item>';
        }

        return $xml.'</channel></rss>';
    }

    #[Test]
    public function komanda_cita_izvore_iz_podesavanja_pa_novi_izvor_radi_bez_izmene_koda(): void
    {
        Http::fake([
            self::A => Http::response($this->feed([['Prva', 'https://a.test/1']])),
            self::B => Http::response($this->feed([['Druga', 'https://b.test/2']])),
        ]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 0, greška: nema')->assertExitCode(0);
        Http::assertNotSent(fn ($zahtev) => $zahtev->url() === self::B);

        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => self::A], ['ime' => 'Izvor B', 'adresa' => self::B]]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor B: novih 1, preskočeno 0, greška: nema')->assertExitCode(0);
        $this->assertSame('Izvor B', Prilika::query()->where('naslov', 'Druga')->sole()->naziv_izvora);
    }

    #[Test]
    public function stavka_postaje_nacrt_sa_poljima_iz_izvora(): void
    {
        Http::fake([self::A => Http::response($this->feed([['Vebinar: „Uloga mentora“ &amp; praksa', 'https://a.test/vebinar', '<p>Prvi red.</p><p>Drugi red.</p>']]))]);

        $this->artisan('uvoz:rss');

        $prilika = Prilika::query()->sole();

        $this->assertSame('Vebinar: „Uloga mentora“ & praksa', $prilika->naslov);
        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame(VrstaPrilike::Drugo, $prilika->vrsta);
        $this->assertSame('Prvi red. Drugi red.', $prilika->kratak_opis);
        $this->assertSame('https://a.test/vebinar', $prilika->link_izvora);
        $this->assertSame('Izvor A', $prilika->naziv_izvora);
        $this->assertNull($prilika->rok);
        $this->assertNull($prilika->opis);
        $this->assertSame('2026-10-05 12:00:00', $prilika->created_at->toDateTimeString());
    }

    #[Test]
    public function kratak_opis_nema_html_skripte_ni_entitete(): void
    {
        Http::fake([self::A => Http::response($this->feed([['Naslov', 'https://a.test/1', '<script>alert(1)</script><p>Tekst &amp; <b>drugo</b>&nbsp;još</p>']]))]);

        $this->artisan('uvoz:rss');

        $this->assertSame('Tekst & drugo još', Prilika::query()->sole()->kratak_opis);
    }

    #[Test]
    public function bez_opisa_kratak_opis_je_naslov(): void
    {
        Http::fake([self::A => Http::response($this->feed([['Samo naslov', 'https://a.test/1']]))]);

        $this->artisan('uvoz:rss');

        $this->assertSame('Samo naslov', Prilika::query()->sole()->kratak_opis);
    }

    #[Test]
    public function predugacak_naslov_i_opis_se_secu_da_stanu_u_polja(): void
    {
        Http::fake([self::A => Http::response($this->feed([[str_repeat('š', 300), 'https://a.test/1', str_repeat('č', 500)]]))]);

        $this->artisan('uvoz:rss');

        $prilika = Prilika::query()->sole();

        $this->assertSame(255, mb_strlen($prilika->naslov));
        $this->assertSame(300, mb_strlen($prilika->kratak_opis));
        $this->assertStringEndsWith('…', $prilika->kratak_opis);
    }

    #[Test]
    public function nista_se_ne_objavljuje_i_ne_menja_postojeca_prilika(): void
    {
        $objavljena = Prilika::factory()->objavljena()->create(['link_izvora' => 'https://a.test/vec-objavljeno', 'naslov' => 'Moja objavljena']);
        $nacrt = Prilika::factory()->create(['naslov' => 'Moj nacrt']);
        $arhivirana = Prilika::factory()->arhivirana()->create(['naslov' => 'Moja arhivirana']);
        $pre = Prilika::query()->orderBy('id')->get()->map->getAttributes()->all();
        $javnihPre = Prilika::javne()->count();

        $this->travelTo(Carbon::create(2026, 10, 6, 9, 0, 0, 'Europe/Belgrade'));
        Http::fake([self::A => Http::response($this->feed([['Pokušaj da prepiše', 'https://a.test/vec-objavljeno', 'Novi opis'], ['Nova', 'https://a.test/nova']]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 1, greška: nema');

        $this->assertSame($pre, Prilika::query()->whereIn('id', [$objavljena->id, $nacrt->id, $arhivirana->id])->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($javnihPre, Prilika::javne()->count());
        $this->assertSame(StatusPrilike::Nacrt, Prilika::query()->where('naslov', 'Nova')->sole()->status);
        $this->assertSame(0, Prilika::query()->where('naslov', 'Pokušaj da prepiše')->count());
    }

    #[Test]
    public function isti_link_ne_pravi_drugi_nacrt_ni_u_drugom_pokretanju_a_nova_stavka_pravi(): void
    {
        $odgovor = $this->feed([['Prva', 'https://a.test/1'], ['Druga', 'https://a.test/2']]);
        Http::fake([self::A => function () use (&$odgovor) {
            return Http::response($odgovor);
        }]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 2, preskočeno 0, greška: nema');
        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 0, preskočeno 2, greška: nema');
        $this->assertSame(2, Prilika::query()->count());

        // Ogledalo: stavka sa novim linkom pravi nacrt, stare ostaju preskočene.
        $odgovor = $this->feed([['Prva', 'https://a.test/1'], ['Druga', 'https://a.test/2'], ['Treća', 'https://a.test/3']]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 2, greška: nema');
        $this->assertSame(3, Prilika::query()->count());
    }

    #[Test]
    public function isti_link_dvaput_u_istom_izvoru_pravi_jedan_nacrt(): void
    {
        Http::fake([self::A => Http::response($this->feed([['Prva', 'https://a.test/1'], ['Prva opet', 'https://a.test/1']]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 1, greška: nema');
        $this->assertSame(1, Prilika::query()->count());
    }

    #[Test]
    public function izvor_koji_ne_radi_ne_zaustavlja_ostale_a_svaki_ispisuje_novih_preskoceno_i_gresku(): void
    {
        config()->set('uvoz.izvori', [
            ['ime' => 'Pao', 'adresa' => 'https://pao.test/feed'],
            ['ime' => 'Bez veze', 'adresa' => 'https://bezveze.test/feed'],
            ['ime' => 'Nije RSS', 'adresa' => 'https://html.test/feed'],
            ['ime' => 'Ispravan', 'adresa' => 'https://ok.test/feed'],
        ]);
        Http::fake([
            'https://pao.test/feed' => Http::response('greška', 503),
            'https://bezveze.test/feed' => fn () => throw new ConnectionException('nema veze'),
            'https://html.test/feed' => Http::response('<html><body>Nije feed</body></html>'),
            'https://ok.test/feed' => Http::response($this->feed([['Ispravna stavka', 'https://ok.test/1']])),
        ]);

        $this->artisan('uvoz:rss')
            ->expectsOutput('Pao: novih 0, preskočeno 0, greška: izvor je odgovorio sa HTTP 503')
            ->expectsOutputToContain('Bez veze: novih 0, preskočeno 0, greška: neočekivana greška:')
            ->expectsOutput('Nije RSS: novih 0, preskočeno 0, greška: odgovor nije RSS')
            ->expectsOutput('Ispravan: novih 1, preskočeno 0, greška: nema')
            ->assertExitCode(1);

        $this->assertSame(['Ispravna stavka'], Prilika::query()->pluck('naslov')->all());
    }

    // Ogledalo: kad svi izvori rade, izlaz je 0.
    #[Test]
    public function kad_svi_izvori_rade_komanda_vraca_nula(): void
    {
        Http::fake([self::A => Http::response($this->feed([]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 0, preskočeno 0, greška: nema')->assertExitCode(0);
    }

    #[Test]
    public function najvise_pedeset_stavki_po_izvoru(): void
    {
        $stavke = [];
        foreach (range(1, 60) as $broj) {
            $stavke[] = ['Stavka '.$broj, 'https://a.test/'.$broj];
        }
        Http::fake([self::A => Http::response($this->feed($stavke))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 50, preskočeno 0, greška: nema');

        $this->assertSame(50, Prilika::query()->count());
        $this->assertSame(1, Prilika::query()->where('naslov', 'Stavka 50')->count());
        $this->assertSame(0, Prilika::query()->where('naslov', 'Stavka 51')->count());
    }

    #[Test]
    public function stavka_bez_naslova_ili_bez_veb_linka_se_preskace(): void
    {
        Http::fake([self::A => Http::response($this->feed([
            ['', 'https://a.test/bez-naslova'],
            ['Skripta', 'javascript:alert(1)'],
            ['Ftp', 'ftp://a.test/fajl'],
            ['Relativan', '/prilika/1'],
            ['Bez linka', ''],
            ['Ispravna', 'https://a.test/ispravna'],
        ]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 5, greška: nema');

        $this->assertSame(['Ispravna'], Prilika::query()->pluck('naslov')->all());
    }

    #[Test]
    public function odgovor_veci_od_dozvoljenog_je_greska_izvora_a_ne_pad_komande(): void
    {
        config()->set('uvoz.najvise_bajtova', 100);
        Http::fake([self::A => Http::response($this->feed([['Prva', 'https://a.test/1', str_repeat('x', 200)]]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 0, preskočeno 0, greška: odgovor je veći od dozvoljene veličine')->assertExitCode(1);
        $this->assertSame(0, Prilika::query()->count());
    }

    #[Test]
    public function nijedan_zahtev_ne_ide_na_pravi_internet(): void
    {
        config()->set('uvoz.izvori', [['ime' => 'Nelažiran', 'adresa' => 'https://nelaziran.test/feed']]);
        Http::fake([self::A => Http::response($this->feed([]))]);

        $this->artisan('uvoz:rss')->expectsOutputToContain('Nelažiran: novih 0, preskočeno 0, greška: neočekivana greška:')->assertExitCode(1);
    }

    #[Test]
    public function cirilica_u_naslovu_i_opisu_postaje_latinica_a_link_ostaje_isti(): void
    {
        $link = 'https://a.test/%D0%BA%D0%BE%D0%BD%D0%BA%D1%83%D1%80%D1%81?x=1&y=2';
        Http::fake([self::A => Http::response($this->feed([['Конкурс за стипендије', str_replace('&', '&amp;', $link), '<p>Љубав и ЊИХОВА вољена Џак</p>']]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 0, greška: nema');

        $prilika = Prilika::query()->sole();

        $this->assertSame('Konkurs za stipendije', $prilika->naslov);
        $this->assertSame('Ljubav i NJIHOVA voljena Džak', $prilika->kratak_opis);
        $this->assertSame($link, $prilika->link_izvora);
        $this->assertSame('Izvor A', $prilika->naziv_izvora);

        // Ćirilica ne remeti ni poređenje linkova: drugo pokretanje ne pravi drugi nacrt.
        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 0, preskočeno 1, greška: nema');
        $this->assertSame(1, Prilika::query()->count());
    }

    // Dvoslovi su duži od slova iz kojih nastaju, pa se seče posle pretvaranja, ne pre.
    #[Test]
    public function cirilica_se_pretvara_pre_secenja_na_duzinu_polja(): void
    {
        Http::fake([self::A => Http::response($this->feed([[str_repeat('љ', 200), 'https://a.test/1', str_repeat('њ', 400)]]))]);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 1, preskočeno 0, greška: nema');

        $prilika = Prilika::query()->sole();

        $this->assertSame(255, mb_strlen($prilika->naslov));
        $this->assertSame(300, mb_strlen($prilika->kratak_opis));
        $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $prilika->naslov.$prilika->kratak_opis));
    }
}
