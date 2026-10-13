<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use App\Services\UvozRss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 74/T1 (Ivan, 13.10.2026.): uvoz:rss čita do pet strana svakog izvora (?paged=2…), staje na prvoj praznoj ili neispravnoj i ne pada.
// Strane 1–3 su pravi snimci feeda „EU mogućnosti - konkursi" od 13.10.2026. (po tri stavke, bez autora); internet se ne koristi.
#[Group('baza')]
class UvozRssStraneTest extends BazaTestCase
{
    use RefreshDatabase;

    private const FEED = 'https://eumogucnosti.rs/category/konkursi/feed/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function snimak(int $strana): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/rss/eu-mogucnosti-konkursi-strana-'.$strana.'.xml'));
    }

    private function prazan(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Probni izvor</title></channel></rss>';
    }

    /** Strana sa tri izmišljene stavke, svaka sa svojom vezom (za strane posle treće). */
    private function izmisljena(int $strana): string
    {
        $stavke = '';

        foreach ([1, 2, 3] as $broj) {
            $stavke .= '<item><title>Stavka '.$strana.'.'.$broj.'</title><link>https://eumogucnosti.rs/strana-'.$strana.'-'.$broj.'/</link></item>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Probni izvor</title>'.$stavke.'</channel></rss>';
    }

    /** @param  array<int, mixed>  $odgovori strana → odgovor; što nije zadato, nije lažirano (stray zahtev) */
    private function lazirajStrane(array $odgovori, string $adresa = self::FEED): void
    {
        $fake = [];

        foreach ($odgovori as $strana => $odgovor) {
            $fake[$strana === 1 ? $adresa : $adresa.(str_contains($adresa, '?') ? '&' : '?').'paged='.$strana] = $odgovor;
        }

        Http::fake($fake);
    }

    /** @return list<string> */
    private function zahtevi(): array
    {
        return Http::recorded()->map(fn (array $par) => $par[0]->url())->values()->all();
    }

    #[Test]
    public function cita_strane_redom_i_staje_na_prvoj_praznoj(): void
    {
        $this->lazirajStrane([1 => Http::response($this->snimak(1)), 2 => Http::response($this->snimak(2)), 3 => Http::response($this->snimak(3)), 4 => Http::response($this->prazan())]);

        $rezultat = app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $this->assertSame(9, $rezultat->novih);
        $this->assertSame(0, $rezultat->preskoceno);
        $this->assertNull($rezultat->greska);
        $this->assertSame(9, Prilika::query()->count());
        $this->assertSame([self::FEED, self::FEED.'?paged=2', self::FEED.'?paged=3', self::FEED.'?paged=4'], $this->zahtevi());
    }

    // Granica je u podešavanju (pet), ne u kodu: šesta strana se ne traži ni kad je ima.
    #[Test]
    public function cita_najvise_pet_strana(): void
    {
        $this->assertSame(5, config('uvoz.najvise_strana'));
        $this->lazirajStrane([1 => Http::response($this->snimak(1)), 2 => Http::response($this->snimak(2)), 3 => Http::response($this->snimak(3)), 4 => Http::response($this->izmisljena(4)), 5 => Http::response($this->izmisljena(5)), 6 => Http::response($this->izmisljena(6))]);

        $rezultat = app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $this->assertSame(15, $rezultat->novih);
        $this->assertCount(5, $this->zahtevi());
        Http::assertNotSent(fn (Request $zahtev) => str_contains($zahtev->url(), 'paged=6'));
    }

    #[Test]
    public function granica_iz_podesavanja_se_menja_bez_izmene_koda(): void
    {
        config()->set('uvoz.najvise_strana', 2);
        $this->lazirajStrane([1 => Http::response($this->snimak(1)), 2 => Http::response($this->snimak(2)), 3 => Http::response($this->snimak(3))]);

        $rezultat = app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $this->assertSame(6, $rezultat->novih);
        $this->assertCount(2, $this->zahtevi());
    }

    /** @return array<string, array{0: int, 1: string, 2: bool}> status, telo i da li veza puca; odgovor druge strane koji prekida čitanje */
    public static function neispravnaDrugaStrana(): array
    {
        // Ispravan RSS sa novom stavkom: druga strana bi dala stavku da je HTTP status ili veličina ne zaustavljaju.
        $ispravan = static fn (string $dopuna = '') => '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Probni izvor</title><item><title>Nova stavka</title><link>https://eumogucnosti.rs/nova/</link></item>'.$dopuna.'</channel></rss>';

        return [
            'HTTP 404' => [404, '', false],
            'HTTP 500' => [500, 'Greška', false],
            'HTTP 500 sa ispravnim RSS-om' => [500, $ispravan(), false],
            'nije RSS' => [200, '<html><body>Strana ne postoji</body></html>', false],
            'nema veze' => [200, '', true],
            'prevelik odgovor' => [200, $ispravan('<!-- '.str_repeat('x', 2_000_001).' -->'), false],
        ];
    }

    // Prva strana je uvezena, druga je neispravna: čitanje staje bez greške, treća se ne traži.
    #[Test]
    #[DataProvider('neispravnaDrugaStrana')]
    public function neispravna_strana_prekida_citanje_a_ne_obara_uvoz(int $status, string $telo, bool $vezaPuca): void
    {
        $druga = $vezaPuca ? fn () => throw new ConnectionException('nema veze') : Http::response($telo, $status);
        $this->lazirajStrane([1 => Http::response($this->snimak(1)), 2 => $druga, 3 => Http::response($this->snimak(3))]);

        $rezultat = app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $this->assertSame(3, $rezultat->novih);
        $this->assertNull($rezultat->greska);
        $this->assertSame(3, Prilika::query()->count());
        // Zahtev koji puca na vezi se ne beleži kao poslat, pa se tada vidi samo prva strana; treća se ne traži ni u jednom slučaju.
        $this->assertSame($vezaPuca ? [self::FEED] : [self::FEED, self::FEED.'?paged=2'], $this->zahtevi());
        Http::assertNotSent(fn (Request $zahtev) => str_contains($zahtev->url(), 'paged=3'));
    }

    // Izvor koji na prvoj strani nema nijednu stavku („EU u Srbiji - konkursi" je tako dane vraćao 0): nema šta da se traži dalje.
    #[Test]
    public function prazna_prva_strana_ne_trazi_sledece(): void
    {
        $this->lazirajStrane([1 => Http::response($this->prazan()), 2 => Http::response($this->snimak(2))]);

        $rezultat = app(UvozRss::class)->uvezi('Izvor bez stavki', self::FEED);

        $this->assertSame(0, $rezultat->novih);
        $this->assertNull($rezultat->greska);
        $this->assertSame([self::FEED], $this->zahtevi());
    }

    // Ogledalo: greška prve strane se i dalje prijavljuje, kao do sada, i druga se ne traži.
    #[Test]
    public function greska_prve_strane_se_i_dalje_prijavljuje(): void
    {
        $this->lazirajStrane([1 => Http::response('', 503), 2 => Http::response($this->snimak(2))]);

        $rezultat = app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $this->assertSame('izvor je odgovorio sa HTTP 503', $rezultat->greska);
        $this->assertSame(0, $rezultat->novih);
        $this->assertSame([self::FEED], $this->zahtevi());
    }

    // Izvor koji ne zna „paged" vraća istu stranu: ništa novo, pa se staje (jedan suvišan zahtev, ne pet).
    #[Test]
    public function izvor_koji_vraca_istu_stranu_se_ne_cita_do_kraja(): void
    {
        $this->lazirajStrane([1 => Http::response($this->snimak(1)), 2 => Http::response($this->snimak(1)), 3 => Http::response($this->snimak(1))]);

        $rezultat = app(UvozRss::class)->uvezi('Izvor bez paged', self::FEED);

        $this->assertSame(3, $rezultat->novih);
        $this->assertSame(0, $rezultat->preskoceno);
        $this->assertSame([self::FEED, self::FEED.'?paged=2'], $this->zahtevi());
    }

    #[Test]
    public function adresa_koja_vec_ima_upit_dobija_paged_sa_ampersandom(): void
    {
        $adresa = 'https://izvor.test/?feed=rss2';
        $this->lazirajStrane([1 => Http::response($this->snimak(1)), 2 => Http::response($this->prazan())], $adresa);

        app(UvozRss::class)->uvezi('Izvor sa upitom', $adresa);

        $this->assertSame([$adresa, $adresa.'&paged=2'], $this->zahtevi());
    }

    // Ono što već postoji se preskače sa svih strana, kao sada.
    #[Test]
    public function drugo_pokretanje_preskace_stavke_sa_svih_strana(): void
    {
        $odgovori = [1 => Http::response($this->snimak(1)), 2 => Http::response($this->snimak(2)), 3 => Http::response($this->snimak(3)), 4 => Http::response($this->prazan())];
        $this->lazirajStrane($odgovori);
        app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $drugi = app(UvozRss::class)->uvezi('EU mogućnosti - konkursi', self::FEED);

        $this->assertSame(0, $drugi->novih);
        $this->assertSame(9, $drugi->preskoceno);
        $this->assertSame(9, Prilika::query()->count());
    }

    // Komanda: izvor sa svojom granicom (Infostud, ne zna „paged") čita samo prvu stranu, ostali koliko piše u podešavanju.
    #[Test]
    public function komanda_postuje_granicu_izvora_a_ostali_koriste_opstu(): void
    {
        config()->set('uvoz.izvori', [
            ['ime' => 'Bez paged', 'adresa' => 'https://bez-paged.test/feed/', 'najvise_strana' => 1],
            ['ime' => 'Sa paged', 'adresa' => self::FEED],
        ]);
        Http::fake([
            'https://bez-paged.test/feed/' => Http::response($this->snimak(1)),
            self::FEED => Http::response($this->snimak(2)),
            self::FEED.'?paged=2' => Http::response($this->snimak(3)),
            self::FEED.'?paged=3' => Http::response($this->prazan()),
        ]);

        $this->artisan('uvoz:rss')
            ->expectsOutput('Bez paged: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Sa paged: novih 6, preskočeno 0, greška: nema')
            ->assertExitCode(0);

        $this->assertSame(['https://bez-paged.test/feed/', self::FEED, self::FEED.'?paged=2', self::FEED.'?paged=3'], $this->zahtevi());
    }
}
