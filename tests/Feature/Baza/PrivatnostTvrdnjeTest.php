<?php

namespace Tests\Feature\Baza;

use App\Models\Organizacija;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\BazaTestCase;

// Paket 80/T3: svaka tvrdnja sa strane „Privatnost" koja nije pokrivena ranijim testom (UpisanoSeNeCuvaTest: upisano se ne čuva; PredloziTest: predlog nosi samo link i napomenu) ima ovde svoje merenje.
#[Group('baza')]
class PrivatnostTvrdnjeTest extends BazaTestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> ime rute; adrese se prave u testu */
    public static function strane(): array
    {
        return ['početna' => ['pocetna'], 'prilike' => ['prilike.index'], 'vodiči' => ['vodici.index'], 'organizacije' => ['organizacije.index'], 'kalendar' => ['kalendar.index'], 'pomoćnik' => ['pomocnik.index'], 'predlozi' => ['predlozi.forma'], 'privatnost' => ['privatnost']];
    }

    // „Samo kolačiće koji su potrebni da radi": sesija i zaštitni žeton obrasca, ništa drugo, na svakoj javnoj strani; sesija nije dostupna skriptu i traje dva sata.
    #[Test]
    #[DataProvider('strane')]
    public function javna_strana_postavlja_samo_kolacic_sesije_i_zastitni_zeton(string $ruta): void
    {
        $this->travelTo('2026-10-30 12:00:00');
        $kolacici = collect($this->get(route($ruta))->assertOk()->headers->getCookies())->keyBy(fn (Cookie $kolacic) => $kolacic->getName());

        $this->assertSame(['XSRF-TOKEN', config('session.cookie')], $kolacici->keys()->sort()->values()->all(), $ruta);
        $this->assertTrue($kolacici[config('session.cookie')]->isHttpOnly());
        $this->assertSame(120, config('session.lifetime'));
        $this->assertEqualsWithDelta(now()->addMinutes(120)->getTimestamp(), $kolacici[config('session.cookie')]->getExpiresTime(), 5);
    }

    // „Server privremeno pamti adresu uređaja i vrstu pregledača": red u tabeli sesija ima obe, nema korisnika, i nema nijedne druge lične kolone. Ogledalo: nijedan podatak iz zahteva sem ova dva.
    #[Test]
    public function sesija_u_bazi_pamti_adresu_i_pregledac_i_nista_licno_vise(): void
    {
        config()->set('session.driver', 'database');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'ProbaPregledac/1.0'])->get(route('organizacije.index'))->assertOk();

        $sesija = DB::table('sessions')->sole();
        $this->assertSame('203.0.113.9', $sesija->ip_address);
        $this->assertSame('ProbaPregledac/1.0', $sesija->user_agent);
        $this->assertNull($sesija->user_id);
        $this->assertSame(['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'], Schema::getColumnListing('sessions'));
        $this->assertStringNotContainsString('203.0.113.9', (string) base64_decode($sesija->payload));
    }

    // „Nema reklama ni praćenja": nijedna skripta, stil, slika, okvir ni obrazac ne vodi van sajta. Veze ka izvorima (<a>) vode, i to je ogledalo da merilo vidi spoljne adrese.
    #[Test]
    #[DataProvider('strane')]
    public function javna_strana_ne_ucitava_nista_sa_drugog_sajta(string $ruta): void
    {
        Prilika::factory()->objavljena()->create();
        $html = $this->get(route($ruta))->assertOk()->getContent();
        $moj = parse_url(url('/'), PHP_URL_HOST);

        preg_match_all('#<(?:script|img|iframe|source|video|audio|embed)\b[^>]*\ssrc="([^"]*)"|<link\b[^>]*\shref="([^"]*)"|<form\b[^>]*\saction="([^"]*)"|url\(\s*[\'"]?([^)\'"]+)#i', $html, $nadjeno);
        $adrese = array_values(array_filter(array_merge($nadjeno[1], $nadjeno[2], $nadjeno[3], $nadjeno[4])));

        $this->assertGreaterThanOrEqual(2, count($adrese), 'merilo mora da obiđe bar stil i skript: '.$ruta);
        foreach ($adrese as $adresa) {
            $host = parse_url($adresa, PHP_URL_HOST);
            $this->assertTrue($host === null || $host === $moj, $ruta.': '.$adresa);
        }
    }

    #[Test]
    public function veze_ka_izvorima_i_organizacijama_vode_na_druge_sajtove(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['link_izvora' => 'https://izvor.example.org/konkurs']);
        $organizacija = Organizacija::factory()->objavljena()->create(['sajt' => 'https://organizacija.example.org/']);
        $moj = parse_url(url('/'), PHP_URL_HOST);

        foreach ([route('prilike.show', $prilika->slug), route('organizacije.show', $organizacija->slug)] as $adresa) {
            preg_match_all('#<a\b[^>]*\shref="(https?://[^"]+)"#', $this->get($adresa)->getContent(), $veze);
            $spoljne = array_filter($veze[1], fn (string $veza) => parse_url($veza, PHP_URL_HOST) !== $moj);

            $this->assertNotEmpty($spoljne, $adresa);
        }
    }
}
