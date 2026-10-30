<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\UvozSpiska;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 68/T4: izvor bez RSS-a, spisak poziva Nacionalne službe za zapošljavanje (HTML). Snimci prave strane su u tests/Fixtures/spiskovi/, internet se ne koristi.
#[Group('baza')]
class UvozSpiskaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const KONKURSI = 'https://www.nsz.gov.rs/sadrzaji/nzs-konkursi/10';

    private const REGIONALNI = 'https://www.nsz.gov.rs/sadrzaji/konkurisi-lokalne-samouprave/11';

    private const IME_KONKURSI = 'Nacionalna služba za zapošljavanje - konkursi';

    private const OPRE_ROMA = 'https://opreromasrbija.rs/rs/vesti';

    private const IME_OPRE_ROMA = 'Opre Roma Srbija - vesti';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function snimak(string $ime): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/spiskovi/'.$ime));
    }

    // Paket 70/T7: od NSZ-a samo „Konkursi NSZ"; regionalni i lokalni spisak (25 nacrta, nijedna objava u probi 68) nije u podešavanju. Paket 84/T5: uz njega vesti Opre Roma Srbija, sa izgledom strane.
    #[Test]
    public function podesavanje_ima_spisak_konkursa_nsz_i_vesti_opre_roma_sa_imenom_adresom_i_izgledom(): void
    {
        $this->assertSame([
            ['ime' => self::IME_KONKURSI, 'adresa' => self::KONKURSI],
            ['ime' => self::IME_OPRE_ROMA, 'adresa' => self::OPRE_ROMA, 'izgled' => 'opre-roma'],
        ], config('uvoz.spiskovi'));
        $this->assertStringNotContainsString('lokalne-samouprave', (string) json_encode(config('uvoz.spiskovi')));
        $this->assertStringNotContainsString('regionalni', mb_strtolower((string) json_encode(config('uvoz.spiskovi'), JSON_UNESCAPED_UNICODE)));
        $this->assertStringNotContainsString('/rom/', (string) json_encode(config('uvoz.spiskovi')));
        $this->assertArrayHasKey('opre-roma', UvozSpiska::IZGLEDI);
    }

    // Snimak ima devet stavki, tri sa „ZATVOREN" u naslovu; šest otvorenih ulazi kao nacrt, tačnog naslova (latinica), datuma i veze.
    #[Test]
    public function otvoreni_pozivi_ulaze_kao_nacrti_a_zatvoreni_se_preskacu(): void
    {
        Http::fake([self::KONKURSI => Http::response($this->snimak('nsz-konkursi.html'))]);

        $rezultat = app(UvozSpiska::class)->uvezi(self::IME_KONKURSI, self::KONKURSI);

        $this->assertNull($rezultat->greska);
        $this->assertSame(6, $rezultat->novih);
        $this->assertSame(3, $rezultat->preskoceno);
        $this->assertSame(6, Prilika::query()->count());
        $this->assertSame(0, Prilika::query()->where('naslov', 'like', '%ZATVOREN%')->count());

        $prva = Prilika::query()->where('link_izvora', 'https://www.nsz.gov.rs/sadrzaj/javni-poziv-poslodavcima-za-dodelu-subvencije-za-zaposljavanje-nezaposlenih-lica-iz-kategorije-teze-zaposljivih-u-2026-godini/77508')->firstOrFail();

        $this->assertSame('JAVNI POZIV POSLODAVCIMA ZA DODELU SUBVENCIJE ZA ZAPOŠLJAVANJE NEZAPOSLENIH LICA IZ KATEGORIJE TEŽE ZAPOŠLJIVIH U 2026. GODINI', $prva->naslov);
        // Adresa sa crticom „–" u sredini stiže kodirana, kao iz pregledača, i ne ispada iz uvoza.
        $this->assertSame(1, Prilika::query()->where('link_izvora', 'https://www.nsz.gov.rs/sadrzaj/javni-pozivi-za-realizaciju-paketa-mera-u-okviru-programa-garancija-za-mlade-%E2%80%93-filijale-nis-krusevac-i-sremska-mitrovica/77521')->count());
        $this->assertSame(StatusPrilike::Nacrt, $prva->status);
        $this->assertSame(VrstaPrilike::Drugo, $prva->vrsta);
        $this->assertNull($prva->rok);
        $this->assertSame(self::IME_KONKURSI, $prva->naziv_izvora);
        $this->assertSame('2026-03-23', $prva->izvor_objavljen->toDateString());
        $this->assertNull($prva->obradeno_at);
    }

    // Ogledalo: zatvoreni pozivi stvarno stoje u snimku, a nijedan naslov nije ćirilicom.
    #[Test]
    public function snimak_ima_zatvorene_pozive_a_uvezeni_naslovi_su_latinica(): void
    {
        $this->assertSame(3, substr_count($this->snimak('nsz-konkursi.html'), 'ЗАТВОРЕН'));

        Http::fake([self::KONKURSI => Http::response($this->snimak('nsz-konkursi.html'))]);
        app(UvozSpiska::class)->uvezi(self::IME_KONKURSI, self::KONKURSI);

        $this->assertGreaterThan(0, Prilika::query()->count());
        foreach (Prilika::query()->pluck('naslov') as $naslov) {
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $naslov), $naslov);
        }
    }

    #[Test]
    public function isti_spisak_drugi_put_ne_pravi_duplikate(): void
    {
        Http::fake([self::KONKURSI => Http::response($this->snimak('nsz-konkursi.html'))]);

        app(UvozSpiska::class)->uvezi(self::IME_KONKURSI, self::KONKURSI);
        $drugi = app(UvozSpiska::class)->uvezi(self::IME_KONKURSI, self::KONKURSI);

        $this->assertSame(0, $drugi->novih);
        $this->assertSame(9, $drugi->preskoceno);
        $this->assertSame(6, Prilika::query()->count());
    }

    #[Test]
    public function komanda_uvozi_svaki_spisak_iz_podesavanja_i_ispisuje_brojeve(): void
    {
        // Drugi spisak istog izgleda (snimak regionalnog spiska) zadat u samom testu: uvoz je opšti, a podešavanje ga nema.
        config()->set('uvoz.spiskovi', [
            ['ime' => self::IME_KONKURSI, 'adresa' => self::KONKURSI],
            ['ime' => 'Nacionalna služba za zapošljavanje - regionalni i lokalni konkursi', 'adresa' => self::REGIONALNI],
        ]);
        Http::fake([self::KONKURSI => Http::response($this->snimak('nsz-konkursi.html')), self::REGIONALNI => Http::response($this->snimak('nsz-regionalni.html'))]);

        $this->artisan('uvoz:spiskovi')
            ->expectsOutput('Nacionalna služba za zapošljavanje - konkursi: novih 6, preskočeno 3, greška: nema')
            ->expectsOutput('Nacionalna služba za zapošljavanje - regionalni i lokalni konkursi: novih 4, preskočeno 0, greška: nema')
            ->assertExitCode(0);

        $this->assertSame(10, Prilika::query()->count());
        $this->assertSame(4, Prilika::query()->where('naziv_izvora', 'Nacionalna služba za zapošljavanje - regionalni i lokalni konkursi')->count());
    }

    // Kad se izgled spiska promeni i ništa se ne pročita, to se kaže u ispisu, a komanda ne pada.
    #[Test]
    public function izmenjen_izgled_strane_se_vidi_u_ispisu_a_ne_pada(): void
    {
        config()->set('uvoz.spiskovi', [['ime' => 'NSZ', 'adresa' => self::KONKURSI]]);
        Http::fake([self::KONKURSI => Http::response('<html><body><div class="lista"><p>Novi izgled</p></div></body></html>')]);

        $this->artisan('uvoz:spiskovi')
            ->expectsOutput('NSZ: novih 0, preskočeno 0, greška: spisak nije pročitan, izgled strane se verovatno promenio')
            ->assertExitCode(1);

        $this->assertSame(0, Prilika::query()->count());
    }

    #[Test]
    public function strana_koja_ne_odgovara_daje_gresku_a_ne_izuzetak(): void
    {
        Http::fake([self::KONKURSI => Http::response('greška', 503)]);

        $rezultat = app(UvozSpiska::class)->uvezi('NSZ', self::KONKURSI);

        $this->assertSame('izvor je odgovorio sa HTTP 503', $rezultat->greska);
        $this->assertSame(0, Prilika::query()->count());
    }

    #[Test]
    public function veza_bez_adrese_sajta_i_datum_koji_se_ne_cita_se_dopunjuju_ili_ostaju_prazni(): void
    {
        $telo = '<article class="news-item"><div><p class="time-published">31.02.2026.</p><h2 class="news-item-title"><a href="/sadrzaj/poziv-bez-adrese/1">ЈАВНИ ПОЗИV</a></h2></div></article>'
            .'<article class="news-item"><h2 class="news-item-title"><a href="javascript:alert(1)">Loša veza</a></h2></article>'
            .'<article class="news-item"><p class="time-published">01.10.2026.</p></article>';
        Http::fake([self::KONKURSI => Http::response('<html><body>'.$telo.'</body></html>')]);

        $rezultat = app(UvozSpiska::class)->uvezi('NSZ', self::KONKURSI);

        $this->assertSame(1, $rezultat->novih);
        $this->assertSame(2, $rezultat->preskoceno);
        $prilika = Prilika::query()->firstOrFail();
        $this->assertSame('https://www.nsz.gov.rs/sadrzaj/poziv-bez-adrese/1', $prilika->link_izvora);
        $this->assertNull($prilika->izvor_objavljen);
    }

    // Paket 84/T5: srpska verzija vesti Opre Roma Srbija (snimak prve strane spiska; opisi, slike i ime osobe u naslovu izbačeni). Strana ima šest vesti sa danom i mesecom bez godine.
    #[Test]
    public function vesti_opre_roma_ulaze_kao_nacrti_sa_prve_strane_spiska(): void
    {
        Http::fake([self::OPRE_ROMA => Http::response($this->snimak('opre-roma-vesti.html'))]);

        $rezultat = app(UvozSpiska::class)->uvezi(self::IME_OPRE_ROMA, self::OPRE_ROMA, 'opre-roma');

        $this->assertNull($rezultat->greska);
        $this->assertSame(6, $rezultat->novih);
        $this->assertSame(0, $rezultat->preskoceno);
        $this->assertSame([
            'Ako niste spremni da reagujete kada fašizam kuca na vrata Roma, onda niste protiv fašizma',
            'Nova vizija moći koju vode Romi',
            'Ime poziva na prekid govora mržnje i ekstremizma u Bujanovcu i traži izvinjenje za romsku zajednicu od odbornika Alternative za promene',
            'Protest ispred češke ambasade. Romi neće da ćute!',
            'Rasizam ubija! Romi žele da žive!',
            'Rom ponovo žrtva policijske brutalnosti!',
        ], Prilika::query()->orderBy('id')->pluck('naslov')->all());

        $prva = Prilika::query()->orderBy('id')->firstOrFail();

        $this->assertSame('https://opreromasrbija.rs/rs/vesti/blog-28-rs', $prva->link_izvora);
        $this->assertSame(self::IME_OPRE_ROMA, $prva->naziv_izvora);
        $this->assertSame(StatusPrilike::Nacrt, $prva->status);
        $this->assertSame(VrstaPrilike::Drugo, $prva->vrsta);
        $this->assertNull($prva->rok);
        $this->assertNull($prva->obradeno_at);
        // Strana piše „09 / May" bez godine, pa datum objave ostaje prazan (rok bez godine se tada ne prima).
        $this->assertSame(6, Prilika::query()->whereNull('izvor_objavljen')->count());
        $this->assertSame(6, Prilika::query()->where('naziv_izvora', self::IME_OPRE_ROMA)->count());
    }

    // Ogledalo: drugi uvoz ne pravi duplikate, i svaki izgled čita samo svoju stranu.
    #[Test]
    public function isti_spisak_opre_roma_drugi_put_ne_pravi_duplikate(): void
    {
        Http::fake([self::OPRE_ROMA => Http::response($this->snimak('opre-roma-vesti.html'))]);

        app(UvozSpiska::class)->uvezi(self::IME_OPRE_ROMA, self::OPRE_ROMA, 'opre-roma');
        $drugi = app(UvozSpiska::class)->uvezi(self::IME_OPRE_ROMA, self::OPRE_ROMA, 'opre-roma');

        $this->assertSame(0, $drugi->novih);
        $this->assertSame(6, $drugi->preskoceno);
        $this->assertSame(6, Prilika::query()->count());
    }

    #[Test]
    public function svaki_izgled_cita_samo_svoju_stranu(): void
    {
        Http::fake([self::OPRE_ROMA => Http::response($this->snimak('opre-roma-vesti.html')), self::KONKURSI => Http::response($this->snimak('nsz-konkursi.html'))]);

        $opreIzgledomNsz = app(UvozSpiska::class)->uvezi(self::IME_OPRE_ROMA, self::OPRE_ROMA);
        $nszIzgledomOpre = app(UvozSpiska::class)->uvezi(self::IME_KONKURSI, self::KONKURSI, 'opre-roma');

        $this->assertSame('spisak nije pročitan, izgled strane se verovatno promenio', $opreIzgledomNsz->greska);
        $this->assertSame('spisak nije pročitan, izgled strane se verovatno promenio', $nszIzgledomOpre->greska);
        $this->assertSame(0, Prilika::query()->count());
    }

    #[Test]
    public function nepoznat_izgled_daje_gresku_bez_poziva_izvora(): void
    {
        Http::fake();

        $rezultat = app(UvozSpiska::class)->uvezi('Izvor', self::OPRE_ROMA, 'nema-takav');

        $this->assertSame('nepoznat izgled strane: nema-takav', $rezultat->greska);
        Http::assertNothingSent();
        $this->assertSame(0, Prilika::query()->count());
    }

    // Komanda uzima izgled iz podešavanja: bez toga bi se Opre Roma čitala izgledom NSZ-a i ne bi dala nijednu vest.
    #[Test]
    public function komanda_cita_izgled_iz_podesavanja_za_oba_spiska(): void
    {
        Http::fake([self::KONKURSI => Http::response($this->snimak('nsz-konkursi.html')), self::OPRE_ROMA => Http::response($this->snimak('opre-roma-vesti.html'))]);

        $this->artisan('uvoz:spiskovi')
            ->expectsOutput('Nacionalna služba za zapošljavanje - konkursi: novih 6, preskočeno 3, greška: nema')
            ->expectsOutput('Opre Roma Srbija - vesti: novih 6, preskočeno 0, greška: nema')
            ->assertExitCode(0);

        $this->assertSame(12, Prilika::query()->count());
        $this->assertSame(6, Prilika::query()->where('naziv_izvora', self::IME_OPRE_ROMA)->count());
    }

    #[Test]
    public function obrada_uzima_i_nacrte_iz_spiskova(): void
    {
        $nacrt = Prilika::factory()->create(['naslov' => 'Poziv sa spiska', 'naziv_izvora' => self::IME_KONKURSI, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'link_izvora' => 'https://www.nsz.gov.rs/sadrzaj/poziv/1']);
        Http::fake(fn (Request $zahtev) => $zahtev->url() === 'http://127.0.0.1:11434/api/chat'
            ? Http::response(['message' => ['content' => json_encode(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.', 'grupe' => [], 'vrsta' => null, 'rok' => null, 'citat' => ''])]])
            : Http::response('<html><body><p>Strana poziva.</p></body></html>'));

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        $this->assertNotNull($nacrt->fresh()->obradeno_at);
    }
}
