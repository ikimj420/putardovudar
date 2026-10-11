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

    // Niz različitih reči, svaka sa brojem: isti niz od četiri reči dvaput bi duga „rečenica" u ovim probama postala blok veza (paket 63).
    private function razlicitiRecnik(string $osnova, int $od, int $do): string
    {
        return implode(' ', array_map(fn (int $i) => $osnova.$i, range($od, $do)));
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
            'citat i datum kojih nema u tekstu' => [['rok' => '2026-10-21', 'citat' => 'Prijave traju do 21. oktobra 2026.'], 'rok ne piše u tekstu strane', 'rok'],
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

    /** @return array<string, array{0: string|null, 1: string, 2: string, 3: string, 4: string|null}> objavljeno u izvoru, rok modela, citat, rečenica na strani, očekivani rok */
    public static function rokoviBezGodine(): array
    {
        return [
            'ista godina' => ['2026-09-20', '2026-10-26', 'Prijave su otvorene do 26. oktobra', 'Prijave su otvorene do 26. oktobra.', '2026-10-26'],
            'godinu ne bira model' => ['2026-09-20', '2031-10-26', 'Prijave su otvorene do 26. oktobra', 'Prijave su otvorene do 26. oktobra.', '2026-10-26'],
            'sledeća godina' => ['2026-11-20', '2027-01-26', 'Prijave su otvorene do 26. januara', 'Prijave su otvorene do 26. januara.', '2027-01-26'],
            'isti dan kao objava' => ['2026-10-26', '2026-10-26', 'Prijave su otvorene do 26. oktobra', 'Prijave su otvorene do 26. oktobra.', '2026-10-26'],
            'od — do, rok je kraj' => ['2026-09-25', '2026-10-12', 'od 28. septembra do 12. oktobra', 'Prijave traju od 28. septembra do 12. oktobra.', '2026-10-12'],
            'brojevima bez godine' => ['2026-09-20', '2026-10-26', 'Rok je 26.10.', 'Rok je 26.10.', '2026-10-26'],
            'bez datuma objave se ne prima' => [null, '2026-10-26', 'Prijave su otvorene do 26. oktobra', 'Prijave su otvorene do 26. oktobra.', null],
            'dan i mesec iz citata nisu u odgovoru' => ['2026-09-20', '2026-10-27', 'Prijave su otvorene do 26. oktobra', 'Prijave su otvorene do 26. oktobra.', null],
            'mesec iz citata nije u odgovoru' => ['2026-09-20', '2026-11-26', 'Prijave su otvorene do 26. oktobra', 'Prijave su otvorene do 26. oktobra.', null],
            'u citatu piše druga godina' => ['2026-09-20', '2026-10-26', 'Prijave su otvorene do 26. oktobra 2027', 'Prijave su otvorene do 26. oktobra 2027.', null],
            'izveden rok je prošao' => ['2026-01-10', '2026-01-26', 'Prijave su otvorene do 26. januara', 'Prijave su otvorene do 26. januara.', null],
        ];
    }

    // Paket 53/T3: dan i mesec bez godine; godina je ona u kojoj je taj dan prvi put posle datuma objave u izvoru.
    #[Test]
    #[DataProvider('rokoviBezGodine')]
    public function rok_bez_godine_se_izvodi_iz_datuma_objave_a_bez_njega_se_ne_prima(?string $objavljeno, string $rokModela, string $citat, string $recenica, ?string $ocekivano): void
    {
        $nacrt = $this->nacrt(['izvor_objavljen' => $objavljeno]);
        Http::fake([self::STRANA => Http::response('<p>Konkurs.</p><p>'.$recenica.'</p><p>'.self::PRAVILO_NA_STRANI.'.</p>'), self::OLLAMA => $this->ollama(['rok' => $rokModela, 'citat' => $citat])]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        if ($ocekivano !== null) {
            $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
            $this->assertSame($ocekivano, $prilika->rok->toDateString());
            $this->assertSame($ocekivano, $prilika->predlog['rok']);
            $this->assertSame($rokModela, $prilika->predlog['rok_modela']);

            return;
        }

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNull($prilika->rok);
        $this->assertContains($prilika->predlog['odbijeno'], ['rok ne piše u tekstu strane', 'rok je prošao']);
        $this->assertSame($objavljeno === '2026-01-10' ? 'rok je prošao' : 'rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
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

    // Paket 40: vrsta se dokazuje rečju sa strane. „Drugo" nema reč, pa ostaje nacrt (ranije je prolazilo kao vrsta sa spiska); paket 68: i kad na strani nije dokazana nijedna druga vrsta.
    #[Test]
    public function vrsta_drugo_ostaje_nacrt_jer_se_ne_moze_dokazati(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Otvoreno je za sve studente.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['vrsta' => 'drugo', 'grupe' => [['grupa' => 'studenti', 'citat' => 'Otvoreno je za sve studente']]])]);

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
            'mladi' => ['mladi', 'Konkurs je za: mlade diplomce'],
            'povratnici' => ['povratnici', 'Program za povratnike iz inostranstva'],
            'organizacije' => ['organizacije', 'Konkurs je za organizacije civilnog društva'],
            'romi' => ['romi', 'Konkurs je namenjen Romkinjama i Romima'],
            'odrasli-bez-skole' => ['odrasli-bez-skole', 'Obuka je za odrasle koji nisu završili osnovnu školu'],
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

    // Paket 68/T3: grupa „Nezaposleni" važi za sve uzraste, pa je za dokaz dovoljna reč „nezaposleni", bez „mladi".
    #[Test]
    public function javni_radovi_za_nezaposlene_bez_reci_mladi_dokazuju_grupu_nezaposleni(): void
    {
        $nacrt = $this->nacrt(['naslov' => 'Javni radovi']);
        $citat = 'Javni radovi za nezaposlene';
        Http::fake([self::STRANA => $this->strana('<p>Poziv za javne radove. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['vrsta' => 'konkurs', 'grupe' => [['grupa' => 'nezaposleni-mladi', 'citat' => $citat]]])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(['nezaposleni-mladi'], $nacrt->fresh()->grupe);
        $this->assertSame('Nezaposleni', Grupa::NezaposleniMladi->getLabel());
    }

    // Paket 68/T2: strana programa za odrasle bez završene škole, sa rokom, objavljuje se sa grupom odrasli-bez-skole.
    #[Test]
    public function program_za_odrasle_bez_zavrsene_skole_se_objavljuje_sa_tom_grupom(): void
    {
        $nacrt = $this->nacrt(['naslov' => 'Program funkcionalnog osnovnog obrazovanja odraslih']);
        $citat = 'Program je namenjen odraslima bez završene osnovne škole';
        Http::fake([self::STRANA => $this->strana('<p>Program funkcionalnog osnovnog obrazovanja odraslih je besplatna obuka. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['vrsta' => 'obuka', 'grupe' => [['grupa' => 'odrasli-bez-skole', 'citat' => $citat]]])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(['odrasli-bez-skole'], $nacrt->fresh()->grupe);
        $this->assertSame(VrstaPrilike::Obuka, $nacrt->fresh()->vrsta);
    }

    // Paket 68/T1: „Mladi" je grupa kad na strani piše „mladi" ili „mlade"; „omladinske organizacije" i „mlađi" nisu mladi.
    #[Test]
    public function recenica_o_mladima_iz_srbije_dokazuje_grupu_mladi(): void
    {
        $nacrt = $this->nacrt();
        $citat = 'Mladi iz Srbije koji imaju 18 godina mogu da se prijave za besplatnu kartu';
        Http::fake([self::STRANA => $this->strana('<p>Poziv. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'mladi', 'citat' => $citat]]])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(['mladi'], $nacrt->fresh()->grupe);
    }

    /** @return array<string, array{0: string}> */
    public static function recenicaKojaNijeMladi(): array
    {
        return [
            'omladinske organizacije' => ['Prijave podnose omladinske i studentske ustanove'],
            'omladinski centar' => ['Omladinski centar organizuje radionice'],
            'mlađi od 30' => ['Mogu da se prijave osobe mlađe od 30 godina'],
            'ćirilično mlađi' => ['Могу да се пријаве особе млађе од 30 година'],
        ];
    }

    // Ogledalo: reč koja samo liči na „mladi" ne dokazuje grupu, pa model koji je predloži ostaje bez grupe (nacrt).
    #[Test]
    #[DataProvider('recenicaKojaNijeMladi')]
    public function recenica_koja_samo_lici_na_mlade_ne_dokazuje_grupu_mladi(string $citat): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana('<p>Poziv. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p>', saPravilom: false), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'mladi', 'citat' => $citat]]])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertNotContains('mladi', (array) $nacrt->fresh()->grupe);
    }

    // Paket 59/T3: citat modela koji doslovno piše na strani, ali nema reč grupe, ne odbija grupu: kod traži na strani rečenicu sa rečju grupe (kao kad
    // citata nema na strani) i nju čuva kao citat. Izvor citata je „kod", a model samo predlaže.
    #[Test]
    #[DataProvider('grupeSaCitatom')]
    public function citat_modela_bez_reci_grupe_koji_pise_na_strani_menja_kod_recenicom_sa_recju_grupe(string $grupa, string $citat): void
    {
        $nacrt = $this->nacrt();
        $strana = '<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p><p>Konkurs je otvoren za sve građane.</p>';
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => $grupa, 'citat' => 'Konkurs je otvoren za sve građane']]])]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status, $grupa);
        $this->assertSame([$grupa], $prilika->grupe);
        $this->assertSame([['grupa' => $grupa, 'citat' => $citat.'.', 'izvor_citata' => 'kod']], $prilika->predlog['grupe']);
        $this->assertSame([$grupa], $prilika->predlog['prihvacene_grupe']);
        $this->assertNull($prilika->predlog['odbijeno']);
    }

    // Ogledalo: bez rečenice sa rečju grupe na strani citat bez reči grupe i dalje ne prolazi; kod ne izmišlja grupu iz ničega.
    #[Test]
    public function citat_modela_bez_reci_grupe_a_bez_recenice_grupe_na_strani_ostaje_nacrt(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => Http::response('<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>Konkurs je otvoren za sve građane.</p>'), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Konkurs je otvoren za sve građane']]])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertNull($nacrt->fresh()->grupe);
        $this->assertSame('pravilo nije potkrepljeno citatom', $nacrt->fresh()->predlog['odbijeno']);
    }

    // Paket 53/T1: kad citata modela nema na strani, kod nalazi na strani rečenicu sa rečju grupe i nju čuva kao citat.
    #[Test]
    #[DataProvider('grupeSaCitatom')]
    public function kad_citata_modela_nema_na_strani_kod_nalazi_recenicu_sa_recju_grupe(string $grupa, string $citat): void
    {
        $nacrt = $this->nacrt();
        $strana = '<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$citat.'.</p><p>Konkurs je otvoren za sve građane.</p>';
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => $grupa, 'citat' => $citat.' i sve ostale']]])]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status, $grupa);
        $this->assertSame([$grupa], $prilika->grupe);
        $this->assertSame([['grupa' => $grupa, 'citat' => $citat.'.', 'izvor_citata' => 'kod']], $prilika->predlog['grupe']);
        $this->assertSame([$grupa], $prilika->predlog['prihvacene_grupe']);
    }

    // Ogledalo: citat modela koji piše na strani ostaje njegov (bez oznake „kod"), a bez rečenice sa rečju grupe na strani grupa ne prolazi.
    #[Test]
    public function citat_modela_na_strani_ostaje_njegov_a_bez_recenice_grupe_na_strani_nema_prolaza(): void
    {
        $recen = $this->nacrt(['naslov' => 'Citat modela', 'link_izvora' => 'https://a.test/model']);
        $bez = $this->nacrt(['naslov' => 'Bez rečenice', 'link_izvora' => 'https://a.test/bez']);
        Http::fake([
            'https://a.test/model' => $this->strana(),
            'https://a.test/bez' => Http::response('<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>Konkurs je otvoren za sve građane.</p>'),
            self::OLLAMA => fn (Request $zahtev) => str_contains($zahtev->data()['messages'][1]['content'], 'Naslov: Bez rečenice')
                ? $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Izmišljeno za studente.']]])
                : $this->ollama(),
        ]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $recen->fresh()->status);
        $this->assertSame([['grupa' => 'studenti', 'citat' => self::PRAVILO_NA_STRANI]], $recen->fresh()->predlog['grupe']);
        $this->assertSame(StatusPrilike::Nacrt, $bez->fresh()->status);
        $this->assertNull($bez->fresh()->grupe);
        $this->assertSame('pravilo nije potkrepljeno citatom', $bez->fresh()->predlog['odbijeno']);
    }

    // Paket 63/T1 (snimak COST poziva, #21 iz probe 59): blok „Povezano" na dnu strane pominje master studije u naslovu druge vesti. To nije citat poziva, pa grupa ne prolazi.
    private const POVEZANO_NA_DNU = 'Podeli sa drugima Povezano COST poziv 2026: Podrška za nove evropske mreže istraživača COST poziv 2026: Podrška za nove evropske mreže istraživača Dve stipendije za punu školarinu za master studije Umetnost i dizajn video-igara Dve stipendije za punu školarinu za master studije Umetnost i dizajn video-igara Search for: Sektori BIZNIS I INOVACIJE Naša web stranica koristi kolačiće kako bi vam pružila najbolje moguće iskustvo.';

    #[Test]
    public function grupa_koju_dokazuje_samo_blok_povezano_ne_prolazi_a_ona_iz_poziva_prolazi(): void
    {
        $poziv = '<p>Konkurs je otvoren. Rok za prijavu je 20. oktobra 2026. godine. Predlog podnosi glavni predlagač u ime mreže istraživača.</p>';
        $bez = $this->nacrt(['naslov' => 'Samo blok', 'link_izvora' => 'https://a.test/blok']);
        $sa = $this->nacrt(['naslov' => 'Sa rečenicom', 'link_izvora' => 'https://a.test/recenica']);
        Http::fake([
            'https://a.test/blok' => Http::response($poziv.'<p>'.self::POVEZANO_NA_DNU.'</p>'),
            'https://a.test/recenica' => Http::response($poziv.'<p>Konkurs je otvoren za master studente iz Srbije.</p><p>'.self::POVEZANO_NA_DNU.'</p>'),
            // Citat modela ne piše na strani (kao kod COST poziva), pa grupu traži kod.
            self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'master-doktorski', 'citat' => 'Poziv je otvoren za master i doktorske studente']]]),
        ]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Nacrt, $bez->fresh()->status);
        $this->assertSame('pravilo nije potkrepljeno citatom', $bez->fresh()->predlog['odbijeno']);
        $this->assertNull($bez->fresh()->grupe);
        // Ogledalo: isti blok na strani sa pravom rečenicom u pozivu — grupa se dokazuje tom rečenicom.
        $this->assertSame(StatusPrilike::Objavljeno, $sa->fresh()->status);
        $this->assertSame(['master-doktorski'], $sa->fresh()->grupe);
        $this->assertSame([['grupa' => 'master-doktorski', 'citat' => 'Konkurs je otvoren za master studente iz Srbije.', 'izvor_citata' => 'kod']], $sa->fresh()->predlog['grupe']);
    }

    // Paket 67 (PITANJA-63.md 3): citat koji vrati sam model, a stoji samo u bloku „Povezano", ne dokazuje grupu; ista rečenica u telu teksta je dokazuje.
    #[Test]
    public function citat_modela_iz_bloka_povezano_ne_dokazuje_grupu_a_isti_citat_iz_tela_dokazuje(): void
    {
        $poziv = '<p>Konkurs je otvoren. Rok za prijavu je 20. oktobra 2026. godine. Predlog podnosi glavni predlagač u ime mreže istraživača.</p>';
        $citat = 'Dve stipendije za punu školarinu za master studije';
        $samoBlok = $this->nacrt(['naslov' => 'Citat iz bloka', 'link_izvora' => 'https://a.test/blok-citat']);
        $uTelu = $this->nacrt(['naslov' => 'Citat iz tela', 'link_izvora' => 'https://a.test/telo-citat']);
        Http::fake([
            'https://a.test/blok-citat' => Http::response($poziv.'<p>'.self::POVEZANO_NA_DNU.'</p>'),
            'https://a.test/telo-citat' => Http::response($poziv.'<p>'.$citat.' su otvorene za sve prijavljene.</p><p>'.self::POVEZANO_NA_DNU.'</p>'),
            self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'master-doktorski', 'citat' => $citat]]]),
        ]);

        $this->artisan('uvoz:obradi');

        // Citat jeste doslovno na strani i ima reč grupe, ali samo u bloku: nacrt.
        $this->assertSame(StatusPrilike::Nacrt, $samoBlok->fresh()->status);
        $this->assertSame('pravilo nije potkrepljeno citatom', $samoBlok->fresh()->predlog['odbijeno']);
        $this->assertNull($samoBlok->fresh()->grupe);
        // Ogledalo: isti citat sa istim blokom, ali i u telu teksta: grupa prolazi sa citatom modela (ne kodovim).
        $this->assertSame(StatusPrilike::Objavljeno, $uTelu->fresh()->status);
        $this->assertSame(['master-doktorski'], $uTelu->fresh()->grupe);
        $this->assertSame([['grupa' => 'master-doktorski', 'citat' => $citat]], $uTelu->fresh()->predlog['grupe']);
    }

    // Rečenica sa namenom ima prednost nad prvom koja grupu samo pominje; citat je doslovan deo strane i kad je rečenica duga.
    #[Test]
    public function kod_bira_recenicu_koja_kaze_kome_je_namenjeno_a_dugu_secie_oko_reci_grupe(): void
    {
        $nacrt = $this->nacrt();
        $uvod = 'Prošle godine je bilo mnogo studenata na predavanju.';
        $namena = 'Konkurs je otvoren za sve studente.';
        $dugacka = 'Ovo je jedna veoma dugačka rečenica '.$this->razlicitiRecnik('stvar', 1, 100).' koja pominje studente tek pri kraju '.$this->razlicitiRecnik('nastavak', 1, 80).' .';
        Http::fake([self::STRANA => Http::response('<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$dugacka.'</p><p>'.$uvod.'</p><p>'.$namena.'</p>'), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Izmišljen citat o studentima']]])]);

        $this->artisan('uvoz:obradi');

        $citat = $nacrt->fresh()->predlog['grupe'][0]['citat'];

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame($namena, $citat);
    }

    #[Test]
    public function duga_recenica_se_seče_oko_reci_grupe_i_ostaje_doslovan_deo_strane(): void
    {
        $nacrt = $this->nacrt();
        $dugacka = 'Ovo je jedna veoma dugačka rečenica '.$this->razlicitiRecnik('stvar', 1, 100).' koja pominje studente tek pri kraju '.$this->razlicitiRecnik('nastavak', 1, 80).' do kraja.';
        $strana = '<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.$dugacka.'</p>';
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Izmišljen citat o studentima']]])]);

        $this->artisan('uvoz:obradi');

        $citat = $nacrt->fresh()->predlog['grupe'][0]['citat'];

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertStringContainsString('studente', $citat);
        $this->assertLessThanOrEqual(300, mb_strlen($citat));
        $this->assertStringContainsString($citat, $dugacka);
        $this->assertLessThan(mb_strlen($dugacka), mb_strlen($citat));
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
        // Strana ima i rečenicu „Objavljen je roman o životu mladih" da citat o romanu doslovno postoji, a ipak ne prolazi; rečenice sa rečju grupe na strani nema.
        Http::fake([self::STRANA => $this->strana('<p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Objavljen je roman o životu mladih.</p><p>Konkurs je otvoren za sve građane Srbije.</p>', saPravilom: false), self::OLLAMA => $this->ollamaBezKljuceva($dopuna)]);

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

    // Posle „pravilo ne važi" i vrste dolazi grupa: nepotkrepljeno pravilo se prijavljuje i kad je i rok loš.
    #[Test]
    public function nepotkrepljeno_pravilo_se_prijavljuje_pre_greske_u_roku(): void
    {
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(saPravilom: false), self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Izmišljeno.']], 'rok' => '2026-10-04'])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo nije potkrepljeno citatom)');
    }

    /** @return array<string, array{0: string, 1: bool}> vrsta koju model predlaže, da li prolazi bez grupe */
    public static function vrsteBezGrupe(): array
    {
        return [
            'posao' => ['posao', true], 'konkurs' => ['konkurs', false], 'stipendija' => ['stipendija', false], 'praksa' => ['praksa', false], 'obuka' => ['obuka', false],
        ];
    }

    // Paket 59/T1: oglas za posao (vrsta dokazana rečju sa strane) važi i bez grupe; sve ostale vrste bez grupe i dalje ostaju nacrt. Strana ima reči svih vrsta.
    #[Test]
    #[DataProvider('vrsteBezGrupe')]
    public function samo_oglas_za_posao_se_objavljuje_bez_grupe(string $vrsta, bool $prolazi): void
    {
        $nacrt = $this->nacrt();
        $strana = '<p>Oglas za posao, praksu, stipendiju, konkurs i obuku. Rok za prijavu je 20. oktobra 2026. godine.</p>';
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['vrsta' => $vrsta, 'grupe' => [], 'razlog' => 'Oglas.'])]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame($prolazi ? StatusPrilike::Objavljeno : StatusPrilike::Nacrt, $prilika->status, $vrsta);
        $this->assertSame($prolazi ? null : 'pravilo nije potkrepljeno citatom', $prilika->predlog['odbijeno'], $vrsta);

        if ($prolazi) {
            $this->assertSame(VrstaPrilike::Posao, $prilika->vrsta);
            $this->assertSame('2026-10-20', $prilika->rok->toDateString());
            $this->assertNull($prilika->grupe);
            $this->assertSame([], $prilika->predlog['prihvacene_grupe']);
            $this->assertSame(ObradaNacrta::PRAVILO, $prilika->predlog['pravilo']);
            $this->assertTrue(Prilika::javne()->whereKey($prilika->id)->exists());
        }
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> strana, odgovor modela, razlog zbog kog oglas za posao ostaje nacrt */
    public static function oglasiZaPosaoKojiOstajuNacrt(): array
    {
        $rok = '<p>Rok za prijavu je 20. oktobra 2026. godine.</p>';

        return [
            'vrsta nije dokazana rečju sa strane' => ['<p>Besplatno predavanje u skladištu.</p>'.$rok, [], 'vrsta nije potkrepljena'],
            'rok ne piše na strani' => ['<p>Oglas za posao. Prijave su otvorene.</p>', [], 'rok ne piše u tekstu strane'],
            'rok je prošao' => ['<p>Oglas za posao. Rok za prijavu je 4. oktobra 2026. godine.</p>', ['rok' => '2026-10-04', 'citat' => 'Rok za prijavu je 4. oktobra 2026. godine'], 'rok je prošao'],
            'bez roka' => ['<p>Oglas za posao.</p>', ['rok' => null, 'citat' => ''], 'nema ispravnog roka'],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('oglasiZaPosaoKojiOstajuNacrt')]
    public function oglas_za_posao_bez_grupe_i_dalje_trazi_vrstu_i_rok(string $strana, array $dopuna, string $razlog): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['vrsta' => 'posao', 'grupe' => [], ...$dopuna])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt ('.$razlog.'): Konkurs za stipendije');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertSame($razlog, $nacrt->fresh()->predlog['odbijeno']);
    }

    /** @return array<string, array{0: bool, 1: string}> mišljenje modela o tome da li tekst važi za objavu i razlog koji je model dao */
    public static function misljenjaModela(): array
    {
        return [
            'model kaže da je prilika' => [true, 'Model kaže.'],
            'model kaže da nije prilika' => [false, 'Model kaže.'],
            // Paket 67: razlog modela koji tvrdi grupu (izmereno u probi 63: 6 od 10 oglasa sa Infostuda) se ne čuva.
            'model kaže da je prilika i tvrdi grupu' => [true, 'Oglas je namenjen nezaposlenim mladima i studentima.'],
            'model kaže da nije prilika i tvrdi grupu' => [false, 'Oglas je namenjen studentima master i doktorskih studija.'],
        ];
    }

    // Paket 63/T3: oglas za posao se objavljuje kad kod sam dokaže vrstu (reč na strani), rok (na strani, nije prošao) i naslov; mišljenje modela (vazi_pravilo) ne računa.
    #[Test]
    #[DataProvider('misljenjaModela')]
    public function oglas_za_posao_se_objavljuje_bez_obzira_na_misljenje_modela(bool $vazi, string $razlogModela): void
    {
        $nacrt = $this->nacrt(['naslov' => 'Magacioner']);
        Http::fake([
            self::STRANA => Http::response('<p>Oglas za posao. Rok za prijavu je 20. oktobra 2026. godine.</p>'),
            self::OLLAMA => $this->ollama(['vazi_pravilo' => $vazi, 'vrsta' => 'posao', 'grupe' => [], 'razlog' => $razlogModela]),
        ]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('objavljen ('.ObradaNacrta::RAZLOG_POSAO.'): Magacioner');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Posao, $prilika->vrsta);
        $this->assertSame('2026-10-20', $prilika->rok->toDateString());
        $this->assertNull($prilika->grupe);
        $this->assertSame(KoJeObjavio::Ollama, $prilika->objavio);
        // Razlog objave za posao je uvek kodov (Ivan, 11.10.2026.), i kad je model rekao da je prilika; šta je model rekao ostaje zapisano u predlogu.
        $this->assertSame('Oglas za posao: vrsta i rok pišu na strani, a naslov nije rezultat ni spisak.', $prilika->razlog_objave);
        $this->assertSame(ObradaNacrta::RAZLOG_POSAO, $prilika->razlog_objave);
        $this->assertSame($razlogModela, $prilika->predlog['razlog']);
        $this->assertSame(ObradaNacrta::PRAVILO, $prilika->predlog['pravilo']);
        $this->assertTrue(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    /** @return array<string, array{0: string}> vrste koje nisu posao */
    public static function vrsteKojeNisuPosao(): array
    {
        return ['konkurs' => ['konkurs'], 'stipendija' => ['stipendija'], 'praksa' => ['praksa'], 'obuka' => ['obuka']];
    }

    // Ogledalo: za sve ostale vrste mišljenje modela važi kao do sada, i kad strana ima sve ostalo (reči vrsta, grupu, rok).
    #[Test]
    #[DataProvider('vrsteKojeNisuPosao')]
    public function za_ostale_vrste_model_i_dalje_odlucuje_da_li_je_prilika(string $vrsta): void
    {
        $nacrt = $this->nacrt();
        $strana = '<p>Oglas za posao, praksu, stipendiju, konkurs i obuku. Rok za prijavu je 20. oktobra 2026. godine.</p><p>'.self::PRAVILO_NA_STRANI.'.</p>';
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['vazi_pravilo' => false, 'vrsta' => $vrsta])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo za objavu ne važi)');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status, $vrsta);
        $this->assertSame('pravilo za objavu ne važi', $nacrt->fresh()->predlog['odbijeno']);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> strana, odgovor modela, razlog zbog kog oglas za posao ostaje nacrt */
    public static function oglasiZaPosaoBezModelaKojiOstajuNacrt(): array
    {
        $rok = '<p>Rok za prijavu je 20. oktobra 2026. godine.</p>';

        return [
            'vrsta ne piše na strani' => ['<p>Besplatno predavanje.</p>'.$rok, [], 'vrsta nije potkrepljena'],
            'rok ne piše na strani' => ['<p>Oglas za posao. Prijave su otvorene.</p>', [], 'rok ne piše u tekstu strane'],
            'rok je prošao' => ['<p>Oglas za posao. Rok za prijavu je 4. oktobra 2026. godine.</p>', ['rok' => '2026-10-04', 'citat' => 'Rok za prijavu je 4. oktobra 2026. godine'], 'rok je prošao'],
            'model nije našao rok' => ['<p>Oglas za posao.</p>', ['rok' => null, 'citat' => ''], 'nema ispravnog roka'],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('oglasiZaPosaoBezModelaKojiOstajuNacrt')]
    public function oglas_za_posao_bez_dokaza_ostaje_nacrt_i_kad_model_kaze_da_nije_prilika(string $strana, array $dopuna, string $razlog): void
    {
        $nacrt = $this->nacrt(['naslov' => 'Magacioner']);
        Http::fake([self::STRANA => Http::response($strana), self::OLLAMA => $this->ollama(['vazi_pravilo' => false, 'vrsta' => 'posao', 'grupe' => [], ...$dopuna])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt ('.$razlog.'): Magacioner');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertSame($razlog, $nacrt->fresh()->predlog['odbijeno']);
    }

    /** @return array<string, array{0: string, 1: bool, 2: bool}> naslov, da li oglas za posao sa tim naslovom prolazi i šta je model rekao */
    public static function naslovi(): array
    {
        $naslovi = [
            'rezultati' => ['Rezultati konkursa za radno mesto', false],
            'lista' => ['Lista kandidata za posao u školi', false],
            'izabrani' => ['Izabrani kandidati za radno mesto', false],
            'dobitnici' => ['Dobitnici stipendije i posla', false],
            'velika slova' => ['LISTA PRIJAVLJENIH KANDIDATA', false],
            'običan oglas' => ['Magacioner', true],
            'oglas sa rečju slične dužine' => ['Rad na kasi u prodavnici', true],
        ];
        $slucajevi = [];

        foreach ($naslovi as $ime => [$naslov, $prolazi]) {
            $slucajevi[$ime.' (model: prilika)'] = [$naslov, $prolazi, true];
            $slucajevi[$ime.' (model: nije prilika)'] = [$naslov, $prolazi, false];
        }

        return $slucajevi;
    }

    // Naslov koji kaže da je rezultat ili spisak nije oglas za posao: nacrt, i kad model kaže da je prilika; običan naslov prolazi.
    #[Test]
    #[DataProvider('naslovi')]
    public function naslov_rezultata_ili_spiska_nije_oglas_za_posao(string $naslov, bool $prolazi, bool $vazi): void
    {
        $nacrt = $this->nacrt(['naslov' => $naslov]);
        Http::fake([self::STRANA => Http::response('<p>Oglas za posao. Rok za prijavu je 20. oktobra 2026. godine.</p>'), self::OLLAMA => $this->ollama(['vazi_pravilo' => $vazi, 'vrsta' => 'posao', 'grupe' => []])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame($prolazi ? StatusPrilike::Objavljeno : StatusPrilike::Nacrt, $nacrt->fresh()->status, $naslov);
        $this->assertSame($prolazi ? null : 'naslov je rezultat ili spisak', $nacrt->fresh()->predlog['odbijeno'], $naslov);
    }

    // Grupe se i dalje upisuju kad ih kod nađe: oglas za posao sa grupom koju strana dokazuje nosi i vrstu i grupu; izmišljena grupa se ne upisuje, a oglas prolazi.
    #[Test]
    public function oglas_za_posao_upisuje_grupe_koje_kod_nadje_a_izmisljene_ne(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([
            self::STRANA => Http::response('<p>Oglas za posao. Rok za prijavu je 20. oktobra 2026. godine.</p><p>Poziv je namenjen studentima.</p>'),
            self::OLLAMA => $this->ollama(['vrsta' => 'posao', 'grupe' => [['grupa' => 'studenti', 'citat' => 'Poziv je namenjen studentima'], ['grupa' => 'romi', 'citat' => 'Izmišljeno za Rome.']]]),
        ]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Posao, $prilika->vrsta);
        $this->assertSame(['studenti'], $prilika->grupe);
        $this->assertSame(['studenti'], $prilika->predlog['prihvacene_grupe']);
    }

    // Paket 59/T1: vrsta se zna pre grupe — nedokazana vrsta se prijavljuje čak i kad grupe nema; dokazana vrsta bez grupe prijavljuje grupu.
    #[Test]
    public function vrsta_se_proverava_pre_grupe(): void
    {
        $bezVrste = $this->nacrt(['naslov' => 'Bez vrste', 'link_izvora' => 'https://a.test/bez-vrste']);
        $bezGrupe = $this->nacrt(['naslov' => 'Bez grupe', 'link_izvora' => 'https://a.test/bez-grupe']);
        $strana = '<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine.</p>';
        // Na strani bez vrste su dokazane dve vrste (konkurs i obuka), pa kod ne bira umesto modela (paket 68/T5), a model kaže treću.
        Http::fake([
            'https://a.test/bez-vrste' => Http::response('<p>Konkurs i radionica. Rok za prijavu je 20. oktobra 2026. godine.</p>'),
            'https://a.test/bez-grupe' => Http::response($strana),
            self::OLLAMA => fn (Request $zahtev) => str_contains($zahtev->data()['messages'][1]['content'], 'Naslov: Bez vrste')
                ? $this->ollama(['vrsta' => 'stipendija', 'grupe' => []])
                : $this->ollama(['vrsta' => 'konkurs', 'grupe' => []]),
        ]);

        $this->artisan('uvoz:obradi');

        $this->assertSame('vrsta nije potkrepljena', $bezVrste->fresh()->predlog['odbijeno']);
        $this->assertSame('pravilo nije potkrepljeno citatom', $bezGrupe->fresh()->predlog['odbijeno']);
    }

    // Paket 59/T2: model kaže samo da li je tekst prilika; uputstvo ne traži da navede grupu da bi pravilo važilo, a oglas za posao važi i bez nje.
    #[Test]
    public function uputstvo_kaze_da_vazi_pravilo_znaci_samo_da_je_tekst_prilika_i_da_posao_vazi_bez_grupe(): void
    {
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi');

        Http::assertSent(function (Request $zahtev) {
            if ($zahtev->url() !== self::OLLAMA) {
                return false;
            }

            $uputstvo = $zahtev->data()['messages'][0]['content'];

            return str_contains($uputstvo, 'Oglas za posao u Srbiji važi i bez navedene grupe.')
                && str_contains($uputstvo, 'vazi_pravilo (true ako je tekst prilika na koju se neko prijavljuje (posao, praksa, stipendija, konkurs ili obuka), a ne rezultat, lista ili vest; da li je grupa dokazana ne ocenjuješ ti, to proverava kod; inače false)')
                && ! str_contains($uputstvo, 'bar jedna grupa navedena')
                && str_contains($uputstvo, 'grupe (lista objekata');
        });
    }

    // Model ne odlučuje o grupi: vazi_pravilo true bez dokazane grupe ne objavljuje (osim posla), a grupa koju kod dokaže objavljuje i kad model u vazi_pravilo ništa o njoj ne kaže.
    #[Test]
    public function vazi_pravilo_true_bez_dokazane_grupe_ne_objavljuje_osim_za_posao(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(saPravilom: false), self::OLLAMA => $this->ollama(['grupe' => []])]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertSame('pravilo nije potkrepljeno citatom', $nacrt->fresh()->predlog['odbijeno']);
    }

    // Paket 59/T4: razmak ispred interpunkcije koji strana ima iz izvlačenja teksta ne sprečava citat (rok i grupa).
    #[Test]
    public function razmak_ispred_interpunkcije_na_strani_ne_sprecava_citat_roka_ni_grupe(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([
            self::STRANA => Http::response('<p>Konkurs. Rok za prijavu je 20. oktobra 2026. godine .</p><p>Poziv je namenjen svim studentima ( i đacima ) .</p>'),
            self::OLLAMA => $this->ollama(['grupe' => [['grupa' => 'studenti', 'citat' => 'Poziv je namenjen svim studentima (i đacima).']]]),
        ]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(['studenti'], $nacrt->fresh()->grupe);
        $this->assertSame('2026-10-20', $nacrt->fresh()->rok->toDateString());
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

    /** @return array<string, array{0: list<string>, 1: string}> izostavljeni ključevi, razlog nacrta */
    public static function izostavljenaPolja(): array
    {
        return [
            'nema vrste' => [['vrsta'], 'vrsta nije sa spiska'],
            'nema roka' => [['rok'], 'nema ispravnog roka'],
            'nema citata roka' => [['citat'], 'rok ne piše u tekstu strane'],
            'nema ništa od troje' => [['vrsta', 'rok', 'citat'], 'vrsta nije sa spiska'],
        ];
    }

    // Paket 53/T4: izostavljena vrsta, rok ili citat roka su isto što i null: odgovor se obrađuje, ali iz nepotpunog odgovora se nikad ne objavljuje.
    /** @param  list<string>  $izostavi */
    #[Test]
    #[DataProvider('izostavljenaPolja')]
    public function izostavljena_vrsta_rok_ili_citat_ostaju_nacrt_a_obrada_se_belezi(array $izostavi, string $razlog): void
    {
        $nacrt = $this->nacrt();
        $forma = array_diff_key(['vazi_pravilo' => true, 'razlog' => 'Konkurs.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine', 'grupe' => [['grupa' => 'studenti', 'citat' => self::PRAVILO_NA_STRANI]]], array_flip($izostavi));
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => Http::response(['message' => ['content' => json_encode($forma, JSON_UNESCAPED_UNICODE)]])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt ('.$razlog.')');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNull($prilika->objavio);
        $this->assertNull($prilika->rok);
        $this->assertNotNull($prilika->obradeno_at);
        $this->assertSame($izostavi, $prilika->predlog['nedostajala_polja']);
        $this->assertFalse(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    // Ogledalo: potpun odgovor se objavljuje i nema izostavljenih polja.
    #[Test]
    public function potpun_odgovor_nema_izostavljenih_polja(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollama()]);

        $this->artisan('uvoz:obradi');

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame([], $nacrt->fresh()->predlog['nedostajala_polja']);
    }

    // Stvaran odgovor za stranu #38 iz probe 41: vrsta i rok su ušli u objekat grupe, grupa „mladi" nije sa spiska, a rok i citat nema na najvišem nivou.
    #[Test]
    public function odgovor_sa_vrstom_i_rokom_unutar_grupe_ostaje_nacrt_i_ne_ponavlja_se(): void
    {
        $nacrt = $this->nacrt();
        $odgovor = ['grupe' => [['grupa' => 'mladi', 'citat' => 'Rok je 15. oktobar 2026.', 'vrsta' => 'konkurs', 'rok' => '2026-10-15']], 'vazi_pravilo' => true, 'razlog' => 'Konkurs za mlade.', 'vrsta' => 'konkurs'];
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => Http::response(['message' => ['content' => json_encode($odgovor, JSON_UNESCAPED_UNICODE)]])]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('ostaje nacrt (pravilo nije potkrepljeno citatom)');
        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.');

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
        $this->assertNull($nacrt->fresh()->rok);
        $this->assertSame(['rok', 'citat'], $nacrt->fresh()->predlog['nedostajala_polja']);
        $this->assertSame(1, $this->pozivaOllame());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function neispravniOblici(): array
    {
        return [
            'nema razloga' => [['vazi_pravilo' => true, 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'x']],
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

            return str_contains($poruke[0]['content'], 'Objavljuje se stvarna prilika (posao, praksa, stipendija, konkurs ili obuka) dostupna u Srbiji bar jednoj od ovih grupa: osnovci, srednjoškolci, studenti, master i doktorski studenti, nezaposleni (bilo kog uzrasta), mladi, povratnici iz inostranstva, organizacije, odrasli bez završene škole — ili ono što je namenjeno Romima.')
                && str_contains($poruke[0]['content'], 'Organizacije su udruženja i organizacije civilnog društva, ne firme')
                && str_contains($poruke[0]['content'], 'grupe (lista objekata')
                && str_contains($poruke[0]['content'], 'nezaposleni-mladi (nezaposleni svih uzrasta, ne samo mladi), mladi (samo kad tekst kaže')
                && str_contains($poruke[0]['content'], 'romi, odrasli-bez-skole (odrasli bez završene osnovne ili srednje škole, nepismeni, bez kvalifikacija); citat')
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
