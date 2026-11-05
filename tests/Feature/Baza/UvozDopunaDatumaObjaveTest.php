<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\ObradaNacrta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 70/T4: uvoz dopunjava datum objave nacrtima koji ga nemaju (uvezeni pre kolone), ništa drugo ne menja i ne dira ono što je neko menjao.
#[Group('baza')]
class UvozDopunaDatumaObjaveTest extends BazaTestCase
{
    use RefreshDatabase;

    private const FEED = 'https://izvor-a.test/feed/';

    private const SPISAK = 'https://www.nsz.gov.rs/sadrzaji/nzs-konkursi/10';

    private const CLAUDE = LazniClaude::ADRESA;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => self::FEED]]);
        config()->set('uvoz.spiskovi', []);
    }

    private function feed(): string
    {
        $stavke = [['Prva', 'https://a.test/1', 'Mon, 05 Oct 2026 12:14:46 +0000'], ['Druga', 'https://a.test/2', 'Mon, 28 Sep 2026 09:49:41 +0000'], ['Treća', 'https://a.test/3', 'Fri, 25 Sep 2026 09:59:19 +0000'], ['Četvrta', 'https://a.test/4', 'Thu, 24 Sep 2026 06:17:37 +0000'], ['Peta', 'https://a.test/5', 'Thu, 24 Sep 2026 06:14:33 +0000'], ['Šesta', 'https://a.test/6', 'Tue, 22 Sep 2026 05:39:55 +0000']];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Probni izvor</title>';

        foreach ($stavke as [$naslov, $link, $datum]) {
            $xml .= '<item><title>'.$naslov.'</title><link>'.$link.'</link><description>Opis.</description><pubDate>'.$datum.'</pubDate></item>';
        }

        return $xml.'</channel></rss>';
    }

    /** @param  array<string, mixed>  $dopuna */
    private function stari(string $link, array $dopuna = []): Prilika
    {
        $prilika = Prilika::factory()->create([...[
            'naslov' => 'Stari nacrt', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'izvor_objavljen' => null,
            'naziv_izvora' => 'Izvor A', 'link_izvora' => $link, 'status' => StatusPrilike::Nacrt,
        ], ...$dopuna]);

        return $prilika->fresh();
    }

    // Vremena idu mimo modela, da updated_at ostane onakav kakav ga test traži.
    private function vreme(Prilika $prilika, ?string $obradeno, string $izmenjeno): void
    {
        Prilika::query()->whereKey($prilika->getKey())->toBase()->update(['obradeno_at' => $obradeno, 'updated_at' => $izmenjeno]);
    }

    /** @return array<string, mixed> sva polja osim datuma objave, vremena kao tekst */
    private function poljaBezDatumaObjave(Prilika $prilika): array
    {
        $polja = $prilika->fresh()->getAttributes();
        unset($polja['izvor_objavljen']);

        return $polja;
    }

    #[Test]
    public function stari_nacrt_bez_datuma_ga_dobija_a_ostalo_se_ne_menja(): void
    {
        Http::fake([self::FEED => Http::response($this->feed())]);
        $obradjen = $this->stari('https://a.test/1', ['predlog' => ['pravilo' => 4, 'odbijeno' => 'rok ne piše u tekstu strane']]);
        $this->vreme($obradjen, '2026-10-11 16:02:19', '2026-10-11 16:02:19');
        $neobradjen = $this->stari('https://a.test/2');
        $this->vreme($neobradjen, null, $neobradjen->created_at->toDateTimeString());
        $pre = $this->poljaBezDatumaObjave($obradjen);

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 4, preskočeno 2, greška: nema, dopunjen datum objave: 2')->assertExitCode(0);

        $this->assertSame('2026-10-05', $obradjen->fresh()->izvor_objavljen->toDateString());
        $this->assertSame('2026-09-28', $neobradjen->fresh()->izvor_objavljen->toDateString());
        // Ništa drugo, ni vreme izmene (od njega zavisi da li se nacrt vidi kao neizmenjen pri --ponovo).
        $this->assertSame($pre, $this->poljaBezDatumaObjave($obradjen));
        $this->assertSame(6, Prilika::query()->count());
    }

    // Ogledalo: ko ima vrednost, ne dira se ni kad izvor kaže drugo; ni objavljena prilika, ni nacrt koji je neko menjao posle obrade ili uvoza.
    #[Test]
    public function postojeci_datum_objavljena_prilika_i_rucno_menjan_nacrt_se_ne_diraju(): void
    {
        Http::fake([self::FEED => Http::response($this->feed())]);
        $saDatumom = $this->stari('https://a.test/1', ['izvor_objavljen' => '2026-09-01']);
        $objavljena = $this->stari('https://a.test/2', ['status' => StatusPrilike::Objavljeno, 'rok' => '2026-12-31', 'vrsta' => VrstaPrilike::Konkurs, 'grupe' => ['studenti'], 'kratak_opis' => 'Opis.', 'razlog_objave' => 'Razlog.']);
        $menjanPosleObrade = $this->stari('https://a.test/3');
        $this->vreme($menjanPosleObrade, '2026-10-11 16:02:19', '2026-10-12 09:00:00');
        $menjanPosleUvoza = $this->stari('https://a.test/4');
        $this->vreme($menjanPosleUvoza, null, $menjanPosleUvoza->created_at->copy()->addHours(3)->toDateTimeString());

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 2, preskočeno 4, greška: nema')->assertExitCode(0);

        $this->assertSame('2026-09-01', $saDatumom->fresh()->izvor_objavljen->toDateString());
        $this->assertNull($objavljena->fresh()->izvor_objavljen);
        $this->assertNull($menjanPosleObrade->fresh()->izvor_objavljen);
        $this->assertNull($menjanPosleUvoza->fresh()->izvor_objavljen);
    }

    // Posle dopune nacrt i dalje ide u --ponovo (vreme izmene je isto), a rok bez godine sad ima odakle da se izvede.
    #[Test]
    public function dopunjen_nacrt_ostaje_za_ponovnu_obradu(): void
    {
        Http::fake([self::FEED => Http::response($this->feed())]);
        $nacrt = $this->stari('https://a.test/1', ['predlog' => ['pravilo' => 4, 'odbijeno' => 'rok ne piše u tekstu strane', 'model' => 'qwen2.5:7b']]);
        $this->vreme($nacrt, '2026-10-11 16:02:19', '2026-10-11 16:02:19');
        $this->artisan('uvoz:rss');
        $this->assertSame('2026-10-05', $nacrt->fresh()->izvor_objavljen->toDateString());

        Http::fake([
            'https://a.test/1' => Http::response('<p>Poziv studentima. Prijave su otvorene od 28. septembra do 12. oktobra na sajtu programa.</p><p>Konkurs je otvoren za sve studente.</p>'),
            self::CLAUDE => LazniClaude::odgovor(json_encode([
                'vazi_pravilo' => true, 'razlog' => 'Poziv.', 'vrsta' => 'konkurs', 'rok' => '2026-10-12', 'citat' => 'Prijave su otvorene od 28. septembra do 12. oktobra na sajtu programa',
                'grupe' => [['grupa' => 'studenti', 'citat' => 'Konkurs je otvoren za sve studente']],
            ], JSON_UNESCAPED_UNICODE)),
        ]);

        $this->travelTo(Carbon::create(2026, 10, 6, 12, 0, 0, 'Europe/Belgrade'));
        $this->artisan('uvoz:obradi', ['--ponovo' => true])->assertExitCode(0);

        $this->assertSame(ObradaNacrta::PRAVILO, $nacrt->fresh()->predlog['pravilo']);
        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame('2026-10-12', $nacrt->fresh()->rok->toDateString());
    }

    #[Test]
    public function isto_radi_i_uvoz_spiska_nsz(): void
    {
        config()->set('uvoz.spiskovi', [['ime' => 'Spisak NSZ', 'adresa' => self::SPISAK]]);
        Http::fake([self::SPISAK => Http::response((string) file_get_contents(base_path('tests/Fixtures/spiskovi/nsz-konkursi.html')))]);
        $stari = $this->stari('https://www.nsz.gov.rs/sadrzaj/javni-poziv-za-sprovodjenje-radne-aktivacije-osoba-sa-invaliditetom-u-2026-godini/77512', ['naziv_izvora' => 'Spisak NSZ']);
        $this->vreme($stari, '2026-10-11 16:02:19', '2026-10-11 16:02:19');
        $saDatumom = $this->stari('https://www.nsz.gov.rs/sadrzaj/javni-poziv-za-realizaciju-mere-stjecanja/1', ['naziv_izvora' => 'Spisak NSZ', 'izvor_objavljen' => '2026-01-01']);

        $this->artisan('uvoz:spiskovi')->expectsOutputToContain('dopunjen datum objave: 1')->assertExitCode(0);

        $this->assertSame('2026-03-23', $stari->fresh()->izvor_objavljen->toDateString());
        $this->assertSame('2026-01-01', $saDatumom->fresh()->izvor_objavljen->toDateString());
        $this->assertSame('2026-10-11 16:02:19', $stari->fresh()->updated_at->toDateTimeString());
    }

    // Stavka bez datuma u izvoru ne menja ništa.
    #[Test]
    public function stavka_bez_datuma_u_izvoru_ostavlja_nacrt_kakav_jeste(): void
    {
        Http::fake([self::FEED => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>X</title><item><title>Bez datuma</title><link>https://a.test/1</link></item></channel></rss>')]);
        $nacrt = $this->stari('https://a.test/1');

        $this->artisan('uvoz:rss')->expectsOutput('Izvor A: novih 0, preskočeno 1, greška: nema')->assertExitCode(0);

        $this->assertNull($nacrt->fresh()->izvor_objavljen);
        Http::assertNotSent(fn (Request $zahtev) => $zahtev->url() === self::CLAUDE);
    }
}
