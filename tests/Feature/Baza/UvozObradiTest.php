<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\ObradaNacrta;
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

    // Rečenica na strani koja dokazuje grupu; lažni model je podrazumevano citira za grupu „studenti".
    private const PRAVILO_NA_STRANI = 'Konkurs je otvoren za sve studente';

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
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za stipendije otvoren za studente.', 'vrsta' => 'konkurs',
            'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine',
            'grupe' => [['grupa' => 'studenti', 'citat' => self::PRAVILO_NA_STRANI]],
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
            ->expectsOutputToContain('objavljen (Konkurs za stipendije otvoren za studente.): Konkurs za stipendije')
            ->expectsOutput('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 0.')
            ->assertExitCode(0);

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Konkurs, $prilika->vrsta);
        $this->assertSame('2026-10-20', $prilika->rok->toDateString());
        $this->assertSame(KoJeObjavio::Ollama, $prilika->objavio);
        $this->assertSame('Konkurs za stipendije otvoren za studente.', $prilika->razlog_objave);
        $this->assertSame(['studenti'], $prilika->grupe);
        $this->assertSame('2026-10-05 12:00:00', $prilika->obradeno_at->toDateTimeString());
        $this->assertSame('qwen2.5:7b', $prilika->predlog['model']);
        $this->assertSame(ObradaNacrta::PRAVILO, $prilika->predlog['pravilo']);
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
            'vrsta bez reči na strani' => [['vrsta' => 'obuka'], 'vrsta nije potkrepljena', 'vrsta'],
            'vrsta drugo se ne može dokazati' => [['vrsta' => 'drugo'], 'vrsta nije potkrepljena', 'vrsta'],
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
        $this->assertSame($forma['razlog'] ?? 'Konkurs za stipendije otvoren za studente.', $prilika->predlog['razlog']);
        $this->assertNull($prilika->grupe);
        $this->assertSame($forma['vrsta'] ?? 'konkurs', $prilika->predlog['vrsta']);
        $this->assertSame('2026-10-05 12:00:00', $prilika->obradeno_at->toDateTimeString());
        $this->assertFalse(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    /** @return array<string, array{0: string, 1: StatusPrilike}> */
    public static function stranaSaRecimaGrupe(): array
    {
        return [
            'citat je deo druge reči' => ['<p>Programi u Romania i Bugarskoj.</p>', StatusPrilike::Nacrt],
            'citat je cela reč' => ['<p>Zajednica Roma u Srbiji.</p>', StatusPrilike::Objavljeno],
        ];
    }

    // Citat piše na strani samo kao cela reč: „Roma" ne piše na strani na kojoj piše samo „Romania".
    #[Test]
    #[DataProvider('stranaSaRecimaGrupe')]
    public function citat_se_na_strani_trazi_kao_cela_rec(string $recenica, StatusPrilike $status): void
    {
        $nacrt = $this->nacrt();
        Http::fake([
            self::STRANA => $this->strana('<p>Rok za prijavu je 20. oktobra 2026. godine.</p>'.$recenica.'<p>Konkurs za stipendije.</p>', false),
            self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'romi', 'citat' => 'Roma']]]),
        ]);

        $this->artisan('uvoz:obradi');

        $this->assertSame($status, $nacrt->fresh()->status);
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

    // Paket 40: vrsta se dokazuje rečju sa strane. „Drugo" nema reč, pa ostaje nacrt (ranije je prolazilo kao vrsta sa spiska).
    #[Test]
    public function vrsta_drugo_ostaje_nacrt_jer_se_ne_moze_dokazati(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['vrsta' => 'drugo'])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (vrsta nije potkrepljena)');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertSame(VrstaPrilike::Drugo, $nacrt->fresh()->vrsta);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> vrsta, reč na strani, reč koje nema */
    public static function vrsteSaRecju(): array
    {
        return [
            'posao' => ['posao', 'Oglas za posao.', 'Oglas za nešto.'],
            'praksa' => ['praksa', 'Plaćena stručna praksa.', 'Plaćena nešto.'],
            'stipendija' => ['stipendija', 'Stipendija za učenje.', 'Nagrada za učenje.'],
            'konkurs' => ['konkurs', 'Javni poziv za prijavu.', 'Javno obaveštenje za prijavu.'],
            'obuka' => ['obuka', 'Besplatna obuka.', 'Besplatno predavanje.'],
        ];
    }

    // Ogledalo: vrsta sa rečju (ili srodnom rečju) na strani prolazi, ista vrsta bez nje ostaje nacrt.
    #[Test]
    #[DataProvider('vrsteSaRecju')]
    public function vrsta_sa_recju_na_strani_prolazi_a_bez_reci_ostaje_nacrt(string $vrsta, string $saRecju, string $bezReci): void
    {
        $sa = $this->nacrt(['naslov' => 'Sa rečju', 'link_izvora' => 'https://a.test/sa']);
        $bez = $this->nacrt(['naslov' => 'Bez reči', 'link_izvora' => 'https://a.test/bez']);
        // Rečenica o grupi nema nijednu reč vrste (ni „konkurs"), da vrstu dokazuje samo ono što test stavlja u $saRecju.
        $rok = '<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Otvoreno je za sve studente.</p>';
        Http::fake([
            'https://a.test/sa' => Http::response('<p>'.$saRecju.'</p>'.$rok),
            'https://a.test/bez' => Http::response('<p>'.$bezReci.'</p>'.$rok),
            self::OLLAMA => $this->ollama(['vrsta' => $vrsta, 'grupe' => [['grupa' => 'studenti', 'citat' => 'Otvoreno je za sve studente']]]),
        ]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $sa->fresh()->status);
        $this->assertSame(VrstaPrilike::from($vrsta), $sa->fresh()->vrsta);
        $this->assertSame(StatusPrilike::Nacrt, $bez->fresh()->status);
        $this->assertSame('vrsta nije potkrepljena', $bez->fresh()->predlog['odbijeno']);
    }

    // Paket 17: nacrti iz Jooble-a se obrađuju istom komandom; nacrt sa nepoznatog izvora i dalje ne.
    #[Test]
    public function nacrt_iz_jooble_a_se_obradjuje_a_sa_nepoznatog_izvora_ne(): void
    {
        $jooble = $this->nacrt(['naslov' => 'Posao sa Jooble-a', 'naziv_izvora' => 'Jooble', 'link_izvora' => 'https://rs.jooble.org/desc/1']);
        $nepoznat = $this->nacrt(['naslov' => 'Nepoznat izvor', 'naziv_izvora' => 'Neki drugi izvor', 'link_izvora' => 'https://rs.jooble.org/desc/2']);
        Http::fake(['https://rs.jooble.org/desc/1' => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('Posao sa Jooble-a');

        $this->assertNotNull($jooble->fresh()->obradeno_at);
        $this->assertNull($nepoznat->fresh()->obradeno_at);
    }

    // Paket 12 i 40: grupa se dokazuje citatom sa strane, kao rok; prihvaćene grupe se upisuju u priliku.
    #[Test]
    public function objavljena_prilika_cuva_grupe_i_citate_koji_pisu_na_strani(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(['studenti'], $prilika->grupe);
        $this->assertSame([['grupa' => 'studenti', 'citat' => self::PRAVILO_NA_STRANI]], $prilika->predlog['grupe']);
        $this->assertSame(['studenti'], $prilika->predlog['prihvacene_grupe']);
        $this->assertNull($prilika->predlog['odbijeno']);
    }

    /** @return array<string, array{0: string, 1: string}> grupa, citat koji piše na strani i sadrži njenu reč */
    public static function grupeSaCitatom(): array
    {
        return [
            'osnovci' => ['osnovci', 'Poziv je namenjen učenicima osnovnih škola'],
            'srednjoskolci' => ['srednjoskolci', 'Prijaviti se mogu srednjoškolci'],
            'studenti' => ['studenti', 'Konkurs je otvoren za sve studente'],
            'master-doktorski' => ['master-doktorski', 'Konkurs je za doktorske studije u inostranstvu'],
            'nezaposleni-mladi' => ['nezaposleni-mladi', 'Poziv za nezaposlene mlade do 30 godina'],
            'povratnici' => ['povratnici', 'Program za povratnike iz inostranstva'],
            'organizacije' => ['organizacije', 'Konkurs je za organizacije civilnog društva'],
            'romi' => ['romi', 'Konkurs je namenjen Romkinjama i Romima'],
        ];
    }

    // Za svaku grupu: citat koji doslovno piše na strani i sadrži reč grupe prolazi. Reč „Srbija" nigde ne piše.
    #[Test]
    #[DataProvider('grupeSaCitatom')]
    public function citat_sa_recju_grupe_koji_pise_na_strani_objavljuje_priliku_za_tu_grupu(string $grupa, string $citat): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana('<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => $grupa, 'citat' => $citat]]])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame([$grupa], $nacrt->fresh()->grupe);
        $this->assertStringNotContainsStringIgnoringCase('srbij', (string) $nacrt->fresh()->predlog['grupe'][0]['citat']);
    }

    // Ogledalo: ista grupa sa citatom bez njene reči (ili citat kog nema na strani) ostaje nacrt, i ništa se ne upisuje u grupe.
    #[Test]
    #[DataProvider('grupeSaCitatom')]
    public function citat_bez_reci_grupe_ili_kog_nema_na_strani_ostaje_nacrt(string $grupa, string $citat): void
    {
        $bezReci = $this->nacrt(['naslov' => 'Bez reči', 'link_izvora' => 'https://a.test/bez-reci']);
        $izmisljen = $this->nacrt(['naslov' => 'Izmišljen', 'link_izvora' => 'https://a.test/izmisljen']);
        $strana = '<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p><p>Konkurs je otvoren za sve građane.</p>';
        Http::fake([
            'https://a.test/bez-reci' => Http::response($strana),
            'https://a.test/izmisljen' => Http::response($strana),
            self::OLLAMA => fn (Request $zahtev) => str_contains($zahtev->data()['messages'][1]['content'], 'Naslov: Bez reči')
                ? $this->ollama(['grupe' => [['grupa' => $grupa, 'citat' => 'Konkurs je otvoren za sve građane']]])
                : $this->ollama(['grupe' => [['grupa' => $grupa, 'citat' => $citat.' i sve ostale']]]),
        ]);

        $this->artisan('uvoz:obradi');

        foreach ([$bezReci, $izmisljen] as $nacrt) {
            $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status, $nacrt->naslov);
            $this->assertNull($nacrt->fresh()->grupe);
            $this->assertSame('pravilo nije potkrepljeno citatom', $nacrt->fresh()->predlog['odbijeno']);
        }
    }

    // Više grupa: prolaze samo one čiji citat piše na strani i ima njihovu reč; objava traži bar jednu.
    #[Test]
    public function vise_grupa_se_upisuje_samo_za_one_koje_su_potkrepljene(): void
    {
        $nacrt = $this->nacrt();
        $strana = '<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>Poziv je za studente i nezaposlene mlade.</p><p>Konkurs je otvoren za sve građane.</p>';
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['grupe' => [
            ['grupa' => 'romi', 'citat' => 'Konkurs je otvoren za sve građane'],
            ['grupa' => 'nezaposleni-mladi', 'citat' => 'Poziv je za studente i nezaposlene mlade'],
            ['grupa' => 'studenti', 'citat' => 'Poziv je za studente i nezaposlene mlade'],
            ['grupa' => 'povratnici', 'citat' => 'Nema ga na strani za povratnike'],
        ]])]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(['studenti', 'nezaposleni-mladi'], $prilika->grupe);
        $this->assertCount(4, $prilika->predlog['grupe']);
        $this->assertSame(['studenti', 'nezaposleni-mladi'], $prilika->predlog['prihvacene_grupe']);
    }

    // Firme nisu na spisku: citat o firmama ne dokazuje nijednu grupu, pa prilika samo za firme ostaje nacrt.
    #[Test]
    public function prilika_samo_za_firme_ostaje_nacrt(): void
    {
        $nacrt = $this->nacrt();
        $citat = 'Konkurs je otvoren za firme i preduzeća u Srbiji';
        Http::fake([self::STRANA => $this->strana('<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'organizacije', 'citat' => $citat]]])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo nije potkrepljeno citatom)');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertNull($nacrt->fresh()->grupe);
    }

    // Snimak izvora EU mogućnosti: YUCOM je „za organizacije civilnog društva", pa prolazi kao Organizacije (ranije ga nije bilo kako objaviti).
    #[Test]
    public function yucom_iz_snimka_prolazi_kao_organizacije(): void
    {
        $xml = simplexml_load_string((string) file_get_contents(base_path('tests/Fixtures/rss/eu-mogucnosti-konkursi.xml')));
        $stavka = collect($xml->channel->item)->first(fn ($item) => str_starts_with((string) $item->title, 'YUCOM'));
        $this->assertNotNull($stavka);

        $nacrt = $this->nacrt(['naslov' => (string) $stavka->title, 'link_izvora' => (string) $stavka->link]);
        $opis = trim((string) $stavka->description);
        $citat = 'Konkurs je za: organizacije civilnog društva i univerzitetske pravne klinike';
        Http::fake([(string) $stavka->link => Http::response('<p>'.nl2br($opis).'</p>'), self::OLLAMA => $this->ollama([
            'grupe' => [['grupa' => 'organizacije', 'citat' => $citat]], 'vrsta' => 'konkurs',
            'rok' => '2026-11-06', 'citat' => 'Rok za prijavu: 6.11.2026. godine do 17:00 časova',
        ])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(['organizacije'], $nacrt->fresh()->grupe);
        $this->assertSame('2026-11-06', $nacrt->fresh()->rok->toDateString());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function pravilaBezCitata(): array
    {
        $studenti = fn (string $citat) => ['grupe' => [['grupa' => 'studenti', 'citat' => $citat]]];

        return [
            'izmišljen citat koga nema na strani' => [$studenti('Konkurs je otvoren za sve studente i đake')],
            'grupa bez svoje reči u citatu' => [$studenti('Rok za prijavu je 20. oktobra 2026. godine')],
            'srbija nije grupa' => [['grupe' => [['grupa' => 'srbija', 'citat' => 'Konkurs je otvoren za sve građane Srbije']]]],
            'roman nije Rom' => [['grupe' => [['grupa' => 'romi', 'citat' => 'Objavljen je roman o životu mladih']]]],
            'grupa van spiska' => [['grupe' => [['grupa' => 'evropljani', 'citat' => self::PRAVILO_NA_STRANI]]]],
            'grupa je null' => [['grupe' => [['grupa' => null, 'citat' => self::PRAVILO_NA_STRANI]]]],
            'grupa je broj' => [['grupe' => [['grupa' => 5, 'citat' => self::PRAVILO_NA_STRANI]]]],
            'prazan citat' => [$studenti('')],
            'citat je null' => [['grupe' => [['grupa' => 'studenti', 'citat' => null]]]],
            'stavka je tekst' => [['grupe' => ['studenti']]],
            'prazna lista grupa' => [['grupe' => []]],
            'grupe nije lista' => [['grupe' => 'studenti']],
            'grupe je null' => [['grupe' => null]],
            'grupe su izostavljene' => [['grupe' => '__izostavi__']],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('pravilaBezCitata')]
    public function pravilo_bez_citata_koji_ga_dokazuje_ostaje_nacrt(array $dopuna): void
    {
        $nacrt = $this->nacrt();
        // Strana ima i rečenicu „Objavljen je roman o životu mladih" da citat o romanu doslovno postoji, a ipak ne prolazi.
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Objavljen je roman o životu mladih.</p><p>Konkurs je otvoren za sve građane Srbije.</p>'), self::OLLAMA => $this->ollamaBezKljuceva($dopuna)]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo nije potkrepljeno citatom): Konkurs za stipendije');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNull($prilika->objavio);
        $this->assertSame('pravilo nije potkrepljeno citatom', $prilika->predlog['odbijeno']);
        $this->assertSame([], $prilika->predlog['prihvacene_grupe']);
        $this->assertNull($prilika->grupe);
        $this->assertNotNull($prilika->obradeno_at);
        $this->assertFalse(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    // Provera pravila je prva posle „pravilo ne važi": nepotkrepljeno pravilo se prijavljuje i kad je i rok loš.
    #[Test]
    public function nepotkrepljeno_pravilo_se_prijavljuje_pre_greske_u_roku(): void
    {
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Izmišljeno.']], 'rok' => '2026-10-04'])]);

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
            'citat' => 'Rok za prijavu je 20. oktobra 2026. godine', 'grupe' => [['grupa' => 'studenti', 'citat' => self::PRAVILO_NA_STRANI]],
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
        Http::fake([self::STRANA => $this->strana('<p>Рок за пријаву је 20. октобра 2026. године.</p><p>Конкурс је отворен за све студенте.</p>', saPravilom: false), self::OLLAMA => $this->ollama()]);

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

            return str_contains($poruke[0]['content'], 'Objavljuje se stvarna prilika (posao, praksa, stipendija, konkurs ili obuka) dostupna u Srbiji bar jednoj od ovih grupa: osnovci, srednjoškolci, studenti, master i doktorski studenti, nezaposleni mladi, povratnici iz inostranstva, organizacije — ili ono što je namenjeno Romima.')
                && str_contains($poruke[0]['content'], 'Organizacije su udruženja i organizacije civilnog društva, ne firme')
                && str_contains($poruke[0]['content'], 'grupe (lista objekata')
                && str_contains($poruke[0]['content'], 'vazi_pravilo (true')
                && strpos($poruke[0]['content'], 'grupe (lista objekata') < strpos($poruke[0]['content'], 'vazi_pravilo (true')
                && ! str_contains($poruke[0]['content'], 'citat_pravila')
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
