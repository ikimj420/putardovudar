<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Services\Claude\Claude;
use App\Services\Model\ModelObrade;
use App\Services\Model\NepoznatModelObrade;
use App\Services\Pomocnik\PisanjeOdgovora;
use App\Services\Pomocnik\PisanjeSablona;
use App\Services\Pomocnik\PitanjeUFormular;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 71/T2, prepisan u paketu 100/T5 (Ivan, 05.11.2026.: „sve treba preko Claude-a"): obrada nacrta, pomoćnik i „Napiši moj" idu na Claude, a MODEL_OBRADE prima samo „claude".
// Nijedan test ne zove pravi API.
#[Group('baza')]
class ModelObradeTest extends BazaTestCase
{
    use RefreshDatabase;

    private const CLAUDE = LazniClaude::ADRESA;

    private const STRANA = 'https://a.test/oglas-1';

    private const KLJUC = 'probni-kljuc-nije-pravi-123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
        config()->set('claude.kljuc', self::KLJUC);
    }

    /** @return array<string, mixed> */
    private function forma(): array
    {
        return [
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za studente.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine',
            'grupe' => [['grupa' => 'studenti', 'citat' => 'Konkurs je otvoren za sve studente']],
        ];
    }

    private function strana(): mixed
    {
        return Http::response('<html><body><h1>Konkurs</h1><p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Konkurs je otvoren za sve studente.</p></body></html>');
    }

    private function nacrt(string $naslov = 'Konkurs za stipendije', string $link = self::STRANA): Prilika
    {
        return Prilika::factory()->create(['naslov' => $naslov, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => $link]);
    }

    private function claudeOdgovor(int $ulaz = 100_000, int $izlaz = 10_000): mixed
    {
        return LazniClaude::odgovor(json_encode($this->forma(), JSON_UNESCAPED_UNICODE), $ulaz, $izlaz);
    }

    // Bez ijednog podešavanja u testu: obrada ide na Claude jer je „claude" podrazumevana vrednost (config/claude.php), a na kraju piše model, pozive, tokene i cenu.
    #[Test]
    public function podrazumevano_obrada_ide_na_claude_a_na_kraju_pise_model_pozive_tokene_i_cenu(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::CLAUDE => $this->claudeOdgovor(2500, 120)]);

        $this->artisan('uvoz:obradi')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 1. Tokeni: ulaz 2500, izlaz 120. Procena cene: $0,0003.')
            ->assertExitCode(0);

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame('claude-haiku-5-5', $nacrt->fresh()->predlog['model']);
        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::CLAUDE && $zahtev->hasHeader('x-api-key', self::KLJUC));
    }

    #[Test]
    public function obrada_zove_claude_za_svaki_nacrt_i_zbraja_potrosnju(): void
    {
        $prva = $this->nacrt('Prvi', 'https://a.test/prvi');
        $druga = $this->nacrt('Drugi', 'https://a.test/drugi');
        Http::fake(['https://a.test/prvi' => $this->strana(), 'https://a.test/drugi' => $this->strana(), self::CLAUDE => $this->claudeOdgovor()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 2. Tokeni: ulaz 200000, izlaz 20000. Procena cene: $0,0300.')
            ->assertExitCode(0);

        $this->assertSame(StatusPrilike::Objavljeno, $prva->fresh()->status);
        $this->assertSame('claude-haiku-5-5', $prva->fresh()->predlog['model']);
        $this->assertSame(StatusPrilike::Objavljeno, $druga->fresh()->status);
        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::CLAUDE && $zahtev->hasHeader('x-api-key', self::KLJUC));
    }

    #[Test]
    public function model_se_bira_iz_podesavanja_a_cena_iz_podesavanja(): void
    {
        config()->set('claude.model', 'claude-sonnet-5-5');
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::CLAUDE => $this->claudeOdgovor(1_000_000, 100_000)]);

        // 1 milion ulaznih po 2 i 100 hiljada izlaznih po 10 = 2 + 1.
        $this->artisan('uvoz:obradi')->expectsOutput('Model: claude-sonnet-5-5. Poziva: 1. Tokeni: ulaz 1000000, izlaz 100000. Procena cene: $3,0000.')->assertExitCode(0);
        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::CLAUDE && $zahtev['model'] === 'claude-sonnet-5-5');
    }

    // Cene nisu u kodu: druga cena u podešavanju daje drugu procenu.
    #[Test]
    public function cena_dolazi_iz_podesavanja(): void
    {
        config()->set('claude.cene', ['claude-haiku-5-5' => ['ulaz' => 4.0, 'izlaz' => 20.0]]);
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::CLAUDE => $this->claudeOdgovor()]);

        // 100 hiljada ulaznih po 4 i 10 hiljada izlaznih po 20 = 0,4 + 0,2.
        $this->artisan('uvoz:obradi')->expectsOutput('Model: claude-haiku-5-5. Poziva: 1. Tokeni: ulaz 100000, izlaz 10000. Procena cene: $0,6000.')->assertExitCode(0);
    }

    // Bez ključa obrada staje, ništa se ne menja, i piše se da nije potrošeno ništa.
    #[Test]
    public function bez_kljuca_obrada_staje_a_nacrt_ostaje_neobradjen(): void
    {
        config()->set('claude.kljuc', '');
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('Claude API: ključ nije podešen (ANTHROPIC_API_KEY); ništa nije promenjeno posle ovog nacrta (obrađeno do sada: 0).')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 0. Tokeni: ulaz 0, izlaz 0. Procena cene: $0,0000.')
            ->assertExitCode(1);

        $this->assertNull($nacrt->fresh()->obradeno_at);
        Http::assertNotSent(fn (Request $zahtev) => $zahtev->url() === self::CLAUDE);
    }

    // Preopterećen API (529): dva ponavljanja pa staje; obrađeni do tada ostaju obrađeni, a potrošnja se i tada ispiše.
    #[Test]
    public function preopterecenje_staje_posle_ponavljanja_a_potrosnja_se_ipak_ispise(): void
    {
        $prvi = $this->nacrt('Prvi', 'https://a.test/prvi');
        $drugi = $this->nacrt('Drugi', 'https://a.test/drugi');
        Http::fake([
            'https://a.test/prvi' => $this->strana(),
            'https://a.test/drugi' => $this->strana(),
            self::CLAUDE => Http::sequence()->push(['content' => [['type' => 'text', 'text' => json_encode($this->forma())]], 'usage' => ['input_tokens' => 100_000, 'output_tokens' => 10_000]], 200)->push(['type' => 'error'], 529)->push(['type' => 'error'], 529)->push(['type' => 'error'], 529),
        ]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('Claude API je odgovorio sa HTTP 529; ništa nije promenjeno posle ovog nacrta (obrađeno do sada: 1).')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 1. Tokeni: ulaz 100000, izlaz 10000. Procena cene: $0,0150.')
            ->assertExitCode(1);

        $this->assertSame(StatusPrilike::Objavljeno, $prvi->fresh()->status);
        $this->assertNull($drugi->fresh()->obradeno_at);
        Sleep::assertSleptTimes(2);
    }

    // Pomoćnik i „Napiši moj" zovu Claude (Ivan, 05.11.2026.): svaki zahtev ide na Claude, nijedan na lokalnu adresu.
    #[Test]
    public function pomocnik_i_napisi_moj_zovu_samo_claude(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15', 'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis']);
        $sablon = Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => "Poštovani,\n\n{tekst}\n\nS poštovanjem,\n{ime}",
        ]);
        Http::fake([self::CLAUDE => function (Request $zahtev) {
            return LazniClaude::jeJson($zahtev)
                ? LazniClaude::odgovor(json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]))
                : LazniClaude::odgovor("Poštovani,\n\nPraksa.\n\nS poštovanjem,\n[IME]");
        }]);

        $odgovor = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();
        $pismo = $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['ime' => 'Ana Anić', 'tekst' => 'Praksa.'], 'rod' => 'zenski'])->assertOk()->getContent();

        $this->assertStringContainsString('Radnik u skladištu', (string) $odgovor);
        $this->assertStringContainsString('Praksa.', (string) $pismo);
        // Pitanje: formular pa odgovor, „Napiši moj": jedan poziv; i svaki zabeleženi zahtev je ka Claude-u.
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $zahtev) => $zahtev->url() !== self::CLAUDE);
    }

    // Izbor modela je jedno mesto: ModelObrade je Claude samo uz tačno „claude"; sve drugo je greška, pa i stara podrazumevana vrednost „ollama" (paket 95, PITANJA-92 14; paket 100).
    // Greška u kucanju („Claude") ne šalje strane nikome: obrada staje i kaže zašto.
    #[Test]
    public function samo_tacno_claude_bira_model(): void
    {
        config()->set('claude.model_obrade', 'claude');
        $this->app->forgetInstance(ModelObrade::class);

        $this->assertInstanceOf(Claude::class, app(ModelObrade::class));
    }

    #[Test]
    public function svaka_druga_vrednost_je_greska_a_poruka_ne_ispisuje_vrednost(): void
    {
        Http::fake(['*' => Http::response('{}')]);

        foreach (['', 'Claude', 'CLAUDE', 'claude ', ' claude', 'ollama', 'Ollama', ' ollama', 'neki-drugi', 'tajna-vrednost-123', null] as $vrednost) {
            config()->set('claude.model_obrade', $vrednost);
            $this->app->forgetInstance(ModelObrade::class);

            try {
                app(ModelObrade::class);
                $this->fail('Vrednost '.var_export($vrednost, true).' je prošla.');
            } catch (NepoznatModelObrade $izuzetak) {
                $this->assertSame('Nepoznata vrednost MODEL_OBRADE: dozvoljeno je samo „claude", tačno tako, malim slovima.', $izuzetak->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    // Komanda kaže poruku i vraća 1, bez poziva ijednom modelu i bez diranja nacrta; dnevni posao zato nastavlja sa ostalim koracima (vidi UvozDnevnoTest).
    #[Test]
    #[DataProvider('pogresnaVrednost')]
    public function obrada_sa_nepoznatim_modelom_staje_sa_porukom_i_nista_ne_dira(string $vrednost): void
    {
        config()->set('claude.model_obrade', $vrednost);
        $nacrt = $this->nacrt();
        Http::fake(['*' => $this->strana()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutput('Nepoznata vrednost MODEL_OBRADE: dozvoljeno je samo „claude", tačno tako, malim slovima.')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertNull($nacrt->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->fresh()->status);
    }

    /** @return array<string, array{0: string}> */
    public static function pogresnaVrednost(): array
    {
        return ['greška u kucanju' => ['Claude'], 'stara vrednost' => ['ollama']];
    }

    #[Test]
    public function model_obrade_je_podrazumevano_claude_isti_objekat_a_pomocnik_trazi_claude(): void
    {
        $this->assertInstanceOf(Claude::class, app(ModelObrade::class));
        $this->assertSame(spl_object_id(app(ModelObrade::class)), spl_object_id(app(ModelObrade::class)));

        foreach ([PitanjeUFormular::class, PisanjeOdgovora::class, PisanjeSablona::class] as $klasa) {
            $konstruktor = (new \ReflectionClass($klasa))->getConstructor();
            $tipovi = array_map(fn (\ReflectionParameter $parametar) => (string) $parametar->getType(), $konstruktor?->getParameters() ?? []);

            $this->assertContains(Claude::class, $tipovi, $klasa);
            $this->assertNotContains(ModelObrade::class, $tipovi, $klasa);
        }
    }

    /** @return array<string, array{0: string, 1: string}> šta model vrati, poruka u ispisu komande */
    public static function neispravanOdgovor(): array
    {
        return [
            'nije JSON' => ['Evo mog mišljenja: može.', 'nije obrađen (odgovor modela nije ispravan JSON)'],
            'bez polja' => ['{"vazi_pravilo": "da"}', 'nije obrađen (odgovor modela nema tražena polja)'],
        ];
    }

    // Paket 72/T3: poruka koju vidi Ivan ne nosi ime modela.
    #[Test]
    #[DataProvider('neispravanOdgovor')]
    public function poruke_o_neispravnom_odgovoru_ne_pominju_ime_modela(string $sadrzaj, string $poruka): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::CLAUDE => LazniClaude::odgovor($sadrzaj)]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain($poruka);

        $this->assertNull($nacrt->fresh()->obradeno_at);
    }
}
