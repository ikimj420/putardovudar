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

// Paket 40: uvoz:obradi --ponovo obrađuje nacrte iz uvoza koje je obradilo staro pravilo; objavljene i ručno menjane ne dira.
#[Group('baza')]
class UvozObradiPonovoTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const STRANA = 'https://a.test/oglas-1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
    }

    // Nacrt kakav je ostao posle starog pravila: obrađen, bez verzije pravila u predlogu, sa osnovom i citatom pravila.
    /** @param  array<string, mixed>  $dopuna */
    private function starNacrt(array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...[
            'naslov' => 'Stari nacrt', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => self::STRANA,
            'obradeno_at' => now(), 'predlog' => ['vrsta' => 'konkurs', 'rok' => null, 'razlog' => 'Staro.', 'citat' => '', 'osnov' => 'srbija', 'citat_pravila' => 'Srbija', 'odbijeno' => 'pravilo nije potkrepljeno citatom', 'model' => 'qwen2.5:7b'],
        ], ...$dopuna]);
    }

    private function strana(): mixed
    {
        return Http::response('<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>Poziv je za sve studente.</p>');
    }

    /** @param  array<string, mixed>  $dopuna */
    private function ollama(array $dopuna = []): mixed
    {
        return Http::response(['message' => ['content' => json_encode([...[
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za studente.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine',
            'grupe' => [['grupa' => 'studenti', 'citat' => 'Poziv je za sve studente']],
        ], ...$dopuna], JSON_UNESCAPED_UNICODE)]]);
    }

    private function pozivaOllame(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA)->count();
    }

    #[Test]
    public function ponovo_obradjuje_nacrt_obradjen_po_starom_pravilu_i_objavljuje_ga_po_novom(): void
    {
        $nacrt = $this->starNacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutputToContain('objavljen (Konkurs za studente.): Stari nacrt')->assertExitCode(0);

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(['studenti'], $prilika->grupe);
        $this->assertSame(ObradaNacrta::PRAVILO, $prilika->predlog['pravilo']);
        $this->assertSame(1, $this->pozivaOllame());
    }

    // Ogledalo: bez --ponovo, obrađeni nacrt se ne uzima ni po starom pravilu.
    #[Test]
    public function bez_zastavice_obradjen_nacrt_se_ne_uzima(): void
    {
        $nacrt = $this->starNacrt();
        $pre = $nacrt->fresh()->getAttributes();
        Http::fake(['*' => $this->strana()]);

        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.');

        Http::assertNothingSent();
        $this->assertSame($pre, $nacrt->fresh()->getAttributes());
    }

    // Ponovna obrada je jedna: posle nje predlog nosi verziju pravila, pa drugo pokretanje ništa ne uzima.
    #[Test]
    public function nacrt_obradjen_po_novom_pravilu_se_ne_obradjuje_ponovo(): void
    {
        $nacrt = $this->starNacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.', 'grupe' => []])]);

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutputToContain('ostaje nacrt (pravilo za objavu ne važi): Stari nacrt');
        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutput('Nema nacrta iz uvoza za obradu.');

        $this->assertSame(ObradaNacrta::PRAVILO, $nacrt->fresh()->predlog['pravilo']);
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertSame(1, $this->pozivaOllame());
    }

    // Objavljene, ručno menjane, sa drugog izvora i obrađene po novom pravilu ostaju kakve jesu.
    #[Test]
    public function objavljene_rucno_menjane_i_tudje_se_ne_diraju(): void
    {
        $objavljena = $this->starNacrt(['naslov' => 'Objavljena', 'status' => StatusPrilike::Objavljeno, 'link_izvora' => 'https://a.test/o']);
        $saVrstom = $this->starNacrt(['naslov' => 'Ivan promenio vrstu', 'vrsta' => VrstaPrilike::Posao, 'link_izvora' => 'https://a.test/v']);
        $saRokom = $this->starNacrt(['naslov' => 'Ivan uneo rok', 'rok' => '2026-12-01', 'link_izvora' => 'https://a.test/r']);
        $arhivirana = $this->starNacrt(['naslov' => 'Arhivirana', 'status' => StatusPrilike::Arhivirano, 'link_izvora' => 'https://a.test/a']);
        $tudja = $this->starNacrt(['naslov' => 'Tuđi izvor', 'naziv_izvora' => 'Neki drugi', 'link_izvora' => 'https://a.test/t']);
        $novo = $this->starNacrt(['naslov' => 'Po novom pravilu', 'link_izvora' => 'https://a.test/n', 'predlog' => ['pravilo' => ObradaNacrta::PRAVILO, 'odbijeno' => 'pravilo za objavu ne važi']]);
        $dopisan = $this->starNacrt(['naslov' => 'Ivan dopisao opis', 'link_izvora' => 'https://a.test/d']);
        $this->travelTo(now()->addHours(3));
        $dopisan->update(['opis' => 'Ivan je dopisao opis.']);
        $pre = Prilika::query()->orderBy('id')->get()->map->getAttributes()->all();
        Http::fake(['*' => $this->strana()]);

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutput('Nema nacrta iz uvoza za obradu.')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertCount(7, $pre);
        $this->assertSame($pre, Prilika::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(StatusPrilike::Objavljeno, $objavljena->fresh()->status);
        $this->assertSame(VrstaPrilike::Posao, $saVrstom->fresh()->vrsta);
        $this->assertNotNull($saRokom->fresh()->rok);
        $this->assertSame(StatusPrilike::Arhivirano, $arhivirana->fresh()->status);
        $this->assertNull($tudja->fresh()->grupe);
        $this->assertSame(ObradaNacrta::PRAVILO, $novo->fresh()->predlog['pravilo']);
    }

    // „Ponovo" je samo za obrađene: neobrađen nacrt ostaje za običan prolaz.
    #[Test]
    public function ponovo_ne_uzima_nacrt_koji_nije_obradjen(): void
    {
        $neobradjen = Prilika::factory()->create(['naslov' => 'Neobrađen', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => 'https://a.test/neobradjen']);
        Http::fake(['*' => $this->strana()]);

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutput('Nema nacrta iz uvoza za obradu.');

        Http::assertNothingSent();
        $this->assertNull($neobradjen->fresh()->obradeno_at);
    }

    // Ogledalo: nacrt koga Ivan nije menjao posle obrade i nacrt koga jeste, u istom prolazu; uzima se samo prvi.
    #[Test]
    public function od_dva_nacrta_uzima_se_samo_onaj_koji_niko_nije_menjao(): void
    {
        $nedirnut = $this->starNacrt(['naslov' => 'Nedirnut', 'link_izvora' => 'https://a.test/nedirnut']);
        $menjan = $this->starNacrt(['naslov' => 'Menjan', 'link_izvora' => 'https://a.test/menjan']);
        $this->travelTo(now()->addHours(3));
        $menjan->update(['opis' => 'Ivan je dopisao opis.']);
        Http::fake(['https://a.test/nedirnut' => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutputToContain('Obrađeno nacrta: 1. Objavljeno: 1.');

        $this->assertSame(StatusPrilike::Objavljeno, $nedirnut->fresh()->status);
        $this->assertSame(StatusPrilike::Nacrt, $menjan->fresh()->status);
        $this->assertSame('Ivan je dopisao opis.', $menjan->fresh()->opis);
        $this->assertArrayNotHasKey('pravilo', $menjan->fresh()->predlog);
    }

    // Granica „niko nije menjao": izmena do minut posle obrade (sat obrade i čuvanja ne mora da je isti) još važi za neizmenjen nacrt,
    // a izmena posle minuta ne.
    #[Test]
    public function izmena_do_minut_posle_obrade_ne_isklucuje_nacrt_a_posle_minuta_iskljucuje(): void
    {
        $pocetak = now();
        $unutra = $this->starNacrt(['naslov' => 'Unutra', 'link_izvora' => 'https://a.test/unutra']);
        $van = $this->starNacrt(['naslov' => 'Van', 'link_izvora' => 'https://a.test/van']);
        $this->travelTo($pocetak->copy()->addSeconds(30));
        $unutra->update(['opis' => 'Izmena posle 30 sekundi.']);
        $this->travelTo($pocetak->copy()->addSeconds(61));
        $van->update(['opis' => 'Izmena posle 61 sekunde.']);
        Http::fake(['https://a.test/unutra' => $this->strana(), self::OLLAMA => $this->ollama()]);

        // Tačan ispis: nacrt van granice nije ni pokušan (ne bi bio „nije obrađeno").
        $this->artisan('uvoz:obradi', ['--ponovo' => true])->expectsOutputToContain('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 0.');

        $this->assertSame(StatusPrilike::Objavljeno, $unutra->fresh()->status);
        $this->assertSame(StatusPrilike::Nacrt, $van->fresh()->status);
        $this->assertArrayNotHasKey('pravilo', $van->fresh()->predlog);
    }
}
