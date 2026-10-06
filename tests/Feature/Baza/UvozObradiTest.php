<?php

namespace Tests\Feature\Baza;

use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class UvozObradiTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const STRANA = 'https://a.test/oglas-1';

    // Rečenica na strani koja dokazuje pravilo; lažni model je podrazumevano citira kao osnov „srbija".
    private const PRAVILO_NA_STRANI = 'Konkurs je otvoren za sve građane Srbije';

    protected function setUp(): void
    {
        parent::setUp();

        // Nijedan zahtev ne ide na pravi internet ni na pravu Ollamu: što nije lažirano, baca izuzetak.
        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function nacrt(array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...[
            'naslov' => 'Konkurs za stipendije', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null,
            'naziv_izvora' => 'Izvor A', 'link_izvora' => self::STRANA,
        ], ...$dopuna]);
    }

    private function strana(string $telo = '<html><body><h1>Konkurs</h1><p>Rok za prijavu je 20. oktobra 2026. godine.</p></body></html>', bool $saPravilom = true): mixed
    {
        return Http::response($telo.($saPravilom ? '<p>'.self::PRAVILO_NA_STRANI.'.</p>' : ''));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function ollama(array $dopuna = []): mixed
    {
        $forma = [...[
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za stipendije otvoren za građane Srbije.', 'vrsta' => 'konkurs',
            'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine',
            'osnov' => 'srbija', 'citat_pravila' => self::PRAVILO_NA_STRANI,
        ], ...$dopuna];

        return Http::response(['message' => ['content' => json_encode($forma, JSON_UNESCAPED_UNICODE)]]);
    }

    private function pozivaOllame(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA)->count();
    }

    #[Test]
    public function ispravan_slucaj_se_objavi_sa_vrstom_rokom_i_razlogom(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('objavljen (Konkurs za stipendije otvoren za građane Srbije.): Konkurs za stipendije')
            ->expectsOutput('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 0.')
            ->assertExitCode(0);

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Konkurs, $prilika->vrsta);
        $this->assertSame('2026-10-20', $prilika->rok->toDateString());
        $this->assertSame(KoJeObjavio::Ollama, $prilika->objavio);
        $this->assertSame('Konkurs za stipendije otvoren za građane Srbije.', $prilika->razlog_objave);
        $this->assertSame('2026-10-05 12:00:00', $prilika->obradeno_at->toDateTimeString());
        $this->assertSame('qwen2.5:7b', $prilika->predlog['model']);
        $this->assertNull($prilika->predlog['odbijeno']);
        $this->assertTrue(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string}> */
    public static function slucajeviKojiOstajuNacrt(): array
    {
        return [
            'pravilo ne važi' => [['vazi_pravilo' => false, 'razlog' => 'Lista dobitnika, nije prilika.'], 'pravilo za objavu ne važi', 'pravilo'],
            'rok koga nema u tekstu' => [['rok' => '2026-10-21'], 'rok ne piše u tekstu strane', 'rok'],
            'citat koga nema u tekstu' => [['citat' => 'Prijave traju do 20. oktobra 2026.'], 'rok ne piše u tekstu strane', 'rok'],
            'rok bez citata' => [['citat' => ''], 'rok ne piše u tekstu strane', 'rok'],
            'prošao rok' => [['rok' => '2026-10-04', 'citat' => 'Rok za prijavu je 4. oktobra 2026. godine'], 'rok je prošao', 'rok'],
            'vrsta van spiska' => [['vrsta' => 'posao-na-crno'], 'vrsta nije sa spiska', 'vrsta'],
            'bez roka' => [['rok' => null, 'citat' => ''], 'nema ispravnog roka', 'rok'],
            'nepostojeći datum' => [['rok' => '2026-02-31'], 'nema ispravnog roka', 'rok'],
        ];
    }

    /**
     * @param  array<string, mixed>  $forma
     */
    #[Test]
    #[DataProvider('slucajeviKojiOstajuNacrt')]
    public function slucaj_koji_ne_prolazi_proveru_ostaje_nacrt_a_predlog_se_cuva(array $forma, string $razlog, string $ocekivano): void
    {
        $nacrt = $this->nacrt();
        $telo = '<p>Rok za prijavu je 20. oktobra 2026. godine.</p> <p>Rok za prijavu je 4. oktobra 2026. godine.</p>';
        Http::fake([self::STRANA => $this->strana($telo), self::OLLAMA => $this->ollama($forma)]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt ('.$razlog.'): Konkurs za stipendije')->assertExitCode(0);

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame(VrstaPrilike::Drugo, $prilika->vrsta);
        $this->assertNull($prilika->rok);
        $this->assertNull($prilika->objavio);
        $this->assertNull($prilika->razlog_objave);
        $this->assertSame($razlog, $prilika->predlog['odbijeno']);
        $this->assertSame($forma['razlog'] ?? 'Konkurs za stipendije otvoren za građane Srbije.', $prilika->predlog['razlog']);
        $this->assertSame($forma['vrsta'] ?? 'konkurs', $prilika->predlog['vrsta']);
        $this->assertSame('2026-10-05 12:00:00', $prilika->obradeno_at->toDateTimeString());
        $this->assertFalse(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    // Ogledalo: rok od danas je još dobar, pa se objavljuje.
    #[Test]
    public function rok_danas_nije_prosao(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 5. oktobra 2026. godine.</p>'), self::OLLAMA => $this->ollama(['rok' => '2026-10-05', 'citat' => 'Rok za prijavu je 5. oktobra 2026. godine'])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
    }

    // Ogledalo: vrsta „drugo" je na spisku, pa prolazi.
    #[Test]
    public function vrsta_drugo_je_sa_spiska(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['vrsta' => 'drugo'])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(VrstaPrilike::Drugo, $nacrt->fresh()->vrsta);
    }

    // Paket 12: pravilo se dokazuje citatom sa strane, kao rok.
    #[Test]
    public function objavljena_prilika_cuva_osnov_i_citat_pravila_koji_pisu_na_strani(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('srbija', $prilika->predlog['osnov']);
        $this->assertSame(self::PRAVILO_NA_STRANI, $prilika->predlog['citat_pravila']);
        $this->assertNull($prilika->predlog['odbijeno']);
    }

    // Ogledalo: osnov „romi" sa citatom koji doslovno piše na strani i ima reč Rom takođe prolazi.
    #[Test]
    public function osnov_romi_sa_citatom_koji_ima_rec_rom_se_objavljuje(): void
    {
        $nacrt = $this->nacrt();
        $citat = 'Konkurs je namenjen Romkinjama i Romima';
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['osnov' => 'romi', 'citat_pravila' => $citat])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame('romi', $nacrt->fresh()->predlog['osnov']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function pravilaBezCitata(): array
    {
        return [
            'izmišljen citat koga nema na strani' => [['citat_pravila' => 'Konkurs je otvoren za sve građane Srbije i regiona']],
            'romi bez reči Rom u citatu' => [['osnov' => 'romi']],
            'srbija bez reči Srbija u citatu' => [['citat_pravila' => 'Rok za prijavu je 20. oktobra 2026. godine']],
            'roman nije Rom' => [['osnov' => 'romi', 'citat_pravila' => 'Objavljen je roman o životu mladih']],
            'osnov van spiska' => [['osnov' => 'evropa']],
            'osnov je null' => [['osnov' => null]],
            'osnov je broj' => [['osnov' => 5]],
            'prazan citat pravila' => [['citat_pravila' => '']],
            'citat pravila je null' => [['citat_pravila' => null]],
            'osnov i citat pravila su izostavljeni' => [['osnov' => '__izostavi__', 'citat_pravila' => '__izostavi__']],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('pravilaBezCitata')]
    public function pravilo_bez_citata_koji_ga_dokazuje_ostaje_nacrt(array $dopuna): void
    {
        $nacrt = $this->nacrt();
        // Strana ima i rečenicu „Objavljen je roman o životu mladih" da citat o romanu doslovno postoji, a ipak ne prolazi.
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Objavljen je roman o životu mladih.</p>'), self::OLLAMA => $this->ollamaBezKljuceva($dopuna)]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo nije potkrepljeno citatom): Konkurs za stipendije');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNull($prilika->objavio);
        $this->assertSame('pravilo nije potkrepljeno citatom', $prilika->predlog['odbijeno']);
        $this->assertNotNull($prilika->obradeno_at);
        $this->assertFalse(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    // Provera pravila je prva posle „pravilo ne važi": nepotkrepljeno pravilo se prijavljuje i kad je i rok loš.
    #[Test]
    public function nepotkrepljeno_pravilo_se_prijavljuje_pre_greske_u_roku(): void
    {
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['citat_pravila' => 'Izmišljeno.', 'rok' => '2026-10-04'])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo nije potkrepljeno citatom)');
    }

    // Ogledalo: potkrepljeno pravilo ne pere loš rok, pa i dalje važi „rok je prošao".
    #[Test]
    public function potkrepljeno_pravilo_ne_zamenjuje_proveru_roka(): void
    {
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 4. oktobra 2026. godine.</p>'), self::OLLAMA => $this->ollama(['rok' => '2026-10-04', 'citat' => 'Rok za prijavu je 4. oktobra 2026. godine'])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (rok je prošao)');
    }

    /** @param  array<string, mixed>  $dopuna */
    private function ollamaBezKljuceva(array $dopuna): mixed
    {
        $izostavi = array_keys(array_filter($dopuna, fn (mixed $vrednost) => $vrednost === '__izostavi__'));
        $forma = [...[
            'vazi_pravilo' => true, 'razlog' => 'Konkurs.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20',
            'citat' => 'Rok za prijavu je 20. oktobra 2026. godine', 'osnov' => 'srbija', 'citat_pravila' => self::PRAVILO_NA_STRANI,
        ], ...array_diff_key($dopuna, array_flip($izostavi))];

        return Http::response(['message' => ['content' => json_encode(array_diff_key($forma, array_flip($izostavi)), JSON_UNESCAPED_UNICODE)]]);
    }

    #[Test]
    public function rucno_unete_i_vec_objavljene_prilike_se_ne_diraju(): void
    {
        $rucni = $this->nacrt(['naslov' => 'Ručni nacrt', 'naziv_izvora' => 'Moj izvor', 'link_izvora' => 'https://a.test/rucni']);
        $objavljena = Prilika::factory()->objavljena()->create(['naslov' => 'Već objavljena', 'naziv_izvora' => 'Izvor A', 'vrsta' => 'drugo', 'link_izvora' => 'https://a.test/objavljena']);
        $izmenjena = $this->nacrt(['naslov' => 'Ivan je izmenio vrstu', 'vrsta' => VrstaPrilike::Posao, 'link_izvora' => 'https://a.test/izmenjena']);
        $saRokom = $this->nacrt(['naslov' => 'Ivan je uneo rok', 'rok' => '2026-12-01', 'link_izvora' => 'https://a.test/sa-rokom']);
        $arhivirana = $this->nacrt(['naslov' => 'Arhivirana', 'link_izvora' => 'https://a.test/arhivirana', 'status' => StatusPrilike::Arhivirano]);
        $pre = Prilika::query()->orderBy('id')->get()->map->getAttributes()->all();
        Http::fake(['*' => $this->strana()]);

        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame($pre, Prilika::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertNotNull($objavljena->fresh());
        $this->assertNotNull($rucni->fresh());
        $this->assertNotNull($izmenjena->fresh());
        $this->assertNotNull($saRokom->fresh());
        $this->assertNotNull($arhivirana->fresh());
    }

    #[Test]
    public function drugo_pokretanje_ne_obradjuje_isti_nacrt_ponovo(): void
    {
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.'])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('Ostalo nacrt: 1');
        $posleProvog = $this->pozivaOllame();

        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.');
        $posleDrugog = $this->pozivaOllame();

        $this->assertSame([1, 1], [$posleProvog, $posleDrugog]);
    }

    #[Test]
    public function kad_ollama_ne_radi_ne_menja_se_nista_a_komanda_to_kaze(): void
    {
        $nacrt = $this->nacrt();
        $pre = $nacrt->fresh()->getAttributes();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => fn () => throw new ConnectionException('nema veze')]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('Ollama ne odgovara na http://127.0.0.1:11434; ništa nije promenjeno')
            ->assertExitCode(1);

        $this->assertSame($pre, $nacrt->fresh()->getAttributes());
        $this->assertNull($nacrt->fresh()->obradeno_at);
        $this->assertNull($nacrt->fresh()->predlog);
    }

    #[Test]
    public function strana_izvora_koja_ne_radi_ne_obelezava_nacrt_kao_obradjen_i_ne_zaustavlja_ostale(): void
    {
        $pokvaren = $this->nacrt(['naslov' => 'Strana ne radi', 'link_izvora' => 'https://a.test/ne-radi']);
        $ispravan = $this->nacrt(['naslov' => 'Ispravan']);
        Http::fake(['https://a.test/ne-radi' => Http::response('greška', 503), self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('nije obrađen (strana je odgovorila sa HTTP 503): Strana ne radi')
            ->expectsOutput('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 1.');

        $this->assertNull($pokvaren->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Objavljeno, $ispravan->fresh()->status);
    }

    #[Test]
    public function odgovor_ollame_koji_nije_ispravan_ne_obelezava_nacrt_kao_obradjen(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => Http::response(['message' => ['content' => 'Evo mog mišljenja: može.']])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('nije obrađen (odgovor Ollame nije ispravan JSON)');

        $this->assertNull($nacrt->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
    }

    #[Test]
    public function odgovor_ollame_bez_trazenih_polja_ne_obelezava_nacrt_kao_obradjen(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => Http::response(['message' => ['content' => '{"vazi_pravilo": "da"}']])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('nije obrađen (odgovor Ollame nema tražena polja)');

        $this->assertNull($nacrt->fresh()->obradeno_at);
    }

    // Pravi model za stranu koja nije prilika vraća null za vrstu, rok i citat (proba, 05.10.2026.).
    #[Test]
    public function pravilo_ne_vazi_a_ostala_polja_su_null_ostaje_nacrt_i_obradjen_je(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['vazi_pravilo' => false, 'razlog' => 'Nije prilika.', 'vrsta' => null, 'rok' => null, 'citat' => null])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo za objavu ne važi): Konkurs za stipendije');
        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNotNull($prilika->obradeno_at);
        $this->assertNull($prilika->predlog['vrsta']);
        $this->assertSame('pravilo za objavu ne važi', $prilika->predlog['odbijeno']);
        $this->assertSame(1, $this->pozivaOllame());
    }

    // Ogledalo: null tamo gde pravilo važi nije izgovor za objavu.
    #[Test]
    public function pravilo_vazi_a_vrsta_ili_citat_su_null_ostaje_nacrt(): void
    {
        $bezVrste = $this->nacrt(['naslov' => 'Bez vrste', 'link_izvora' => 'https://a.test/bez-vrste']);
        $bezCitata = $this->nacrt(['naslov' => 'Bez citata', 'link_izvora' => 'https://a.test/bez-citata']);
        Http::fake([
            'https://a.test/bez-vrste' => $this->strana(),
            'https://a.test/bez-citata' => $this->strana(),
            self::OLLAMA => fn (Request $zahtev) => str_contains($zahtev->data()['messages'][1]['content'], 'Naslov: Bez vrste')
                ? $this->ollama(['vrsta' => null])
                : $this->ollama(['citat' => null]),
        ]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('ostaje nacrt (vrsta nije sa spiska): Bez vrste')
            ->expectsOutputToContain('ostaje nacrt (rok ne piše u tekstu strane): Bez citata');

        $this->assertSame(StatusPrilike::Nacrt, $bezVrste->fresh()->status);
        $this->assertSame(StatusPrilike::Nacrt, $bezCitata->fresh()->status);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function neispravniOblici(): array
    {
        return [
            'nema razloga' => [['vazi_pravilo' => true, 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'x']],
            'nema ključa vrste' => [['vazi_pravilo' => true, 'razlog' => 'x', 'rok' => '2026-10-20', 'citat' => 'x']],
            'vrsta je broj' => [['vazi_pravilo' => true, 'razlog' => 'x', 'vrsta' => 5, 'rok' => null, 'citat' => '']],
            'rok je broj' => [['vazi_pravilo' => true, 'razlog' => 'x', 'vrsta' => 'konkurs', 'rok' => 20261020, 'citat' => '']],
            'pravilo je tekst' => [['vazi_pravilo' => 'da', 'razlog' => 'x', 'vrsta' => null, 'rok' => null, 'citat' => null]],
        ];
    }

    /** @param  array<string, mixed>  $forma */
    #[Test]
    #[DataProvider('neispravniOblici')]
    public function odgovor_neispravnog_oblika_se_ne_racuna_kao_obrada(array $forma): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => Http::response(['message' => ['content' => json_encode($forma)]])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('nije obrađen (odgovor Ollame nema tražena polja)');

        $this->assertNull($nacrt->fresh()->obradeno_at);
    }

    #[Test]
    public function cirilica_sa_strane_ide_modelu_i_proveri_kao_latinica(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana('<p>Рок за пријаву је 20. октобра 2026. године.</p><p>Конкурс је отворен за све грађане Србије.</p>', saPravilom: false), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi');

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA
            && str_contains($zahtev->data()['messages'][1]['content'], 'Rok za prijavu je 20. oktobra 2026. godine')
            && preg_match('/\p{Cyrillic}/u', $zahtev->data()['messages'][1]['content']) === 0);
        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
    }

    #[Test]
    public function model_dobija_pravilo_za_objavu_doslovno_a_tekst_strane_se_ogranicava(): void
    {
        $this->nacrt();
        config()->set('ollama.najvise_znakova', 50);
        Http::fake([self::STRANA => $this->strana('<p>'.str_repeat('Dugačak tekst. ', 100).'</p>'), self::OLLAMA => $this->ollama(['vazi_pravilo' => false])]);

        $this->artisan('uvoz:obradi');

        Http::assertSent(function (Request $zahtev) {
            if ($zahtev->url() !== self::OLLAMA) {
                return false;
            }

            $poruke = $zahtev->data()['messages'];

            return str_contains($poruke[0]['content'], 'Objavljuje se stvarna prilika (posao, praksa, stipendija, konkurs ili obuka) dostupna ljudima iz Srbije, ili ono što je namenjeno Romima.')
                && str_contains($poruke[0]['content'], 'osnov (srbija ako tekst kaže')
                && str_contains($poruke[0]['content'], 'vazi_pravilo (true')
                && strpos($poruke[0]['content'], 'osnov (srbija ako tekst kaže') < strpos($poruke[0]['content'], 'vazi_pravilo (true')
                && str_contains($poruke[0]['content'], 'citat_pravila (tačan isečak iz teksta koji to kaže i sadrži reč Srbija ili Rom')
                && str_contains($poruke[1]['content'], "Tekst strane:\n".str_repeat('Dugačak tekst. ', 3).'Du')
                && ! str_contains($poruke[1]['content'], str_repeat('Dugačak tekst. ', 4));
        });
    }

    /** @return array<string, array{0: ResponseSequence|PromiseInterface|callable, 1: string}> */
    public static function straniceKojeSeNePrihvataju(): array
    {
        return [
            'greška servera' => [fn () => Http::response('greška', 503), 'strana je odgovorila sa HTTP 503'],
            'bez teksta' => [fn () => Http::response('<html><body><script>var a = 1;</script></body></html>'), 'strana nema teksta'],
            'veća od dozvoljene' => [fn () => Http::response(str_repeat('a', 2_000_001)), 'strana je veća od dozvoljene veličine'],
            'ne odgovara' => [fn () => fn () => throw new ConnectionException('nema veze'), 'strana ne odgovara'],
        ];
    }

    #[Test]
    #[DataProvider('straniceKojeSeNePrihvataju')]
    public function strana_koja_se_ne_prihvata_ne_zove_ollamu_i_ne_obelezava_nacrt(callable $strana, string $razlog): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('nije obrađen ('.$razlog.'): Konkurs za stipendije');

        $this->assertSame(0, $this->pozivaOllame());
        $this->assertNull($nacrt->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
    }
}
