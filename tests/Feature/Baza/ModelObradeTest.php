<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Services\Claude\Claude;
use App\Services\Model\ModelObrade;
use App\Services\Ollama\Ollama;
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

// Paket 71/T2: MODEL_OBRADE bira model samo za obradu nacrta; pomoćnik i „Napiši moj" uvek idu na lokalnu Ollamu. Nijedan test ne zove pravi API.
#[Group('baza')]
class ModelObradeTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const ANTHROPIC = 'https://api.anthropic.com/v1/messages';

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

    private function ollamaOdgovor(): mixed
    {
        return Http::response(['message' => ['content' => json_encode($this->forma(), JSON_UNESCAPED_UNICODE)], 'prompt_eval_count' => 2500, 'eval_count' => 120]);
    }

    private function claudeOdgovor(int $ulaz = 100_000, int $izlaz = 10_000): mixed
    {
        return Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->forma(), JSON_UNESCAPED_UNICODE)]], 'usage' => ['input_tokens' => $ulaz, 'output_tokens' => $izlaz]]);
    }

    #[Test]
    public function podrazumevano_obrada_ide_na_ollamu_a_na_kraju_pise_model_pozive_i_tokene_bez_cene(): void
    {
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::OLLAMA => $this->ollamaOdgovor()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutput('Model: qwen2.5:7b. Poziva: 1. Tokeni: ulaz 2500, izlaz 120. Procena cene: nema (lokalni model ili nepoznata cena).')
            ->assertExitCode(0);

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame('qwen2.5:7b', $nacrt->fresh()->predlog['model']);
        Http::assertNotSent(fn (Request $zahtev) => str_contains($zahtev->url(), 'anthropic'));
    }

    #[Test]
    public function sa_claude_obrada_zove_anthropic_a_ne_ollamu_i_pise_potrosnju_i_cenu(): void
    {
        config()->set('claude.model_obrade', 'claude');
        $prva = $this->nacrt('Prvi', 'https://a.test/prvi');
        $druga = $this->nacrt('Drugi', 'https://a.test/drugi');
        Http::fake(['https://a.test/prvi' => $this->strana(), 'https://a.test/drugi' => $this->strana(), self::ANTHROPIC => $this->claudeOdgovor()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 2. Tokeni: ulaz 200000, izlaz 20000. Procena cene: $0,0300.')
            ->assertExitCode(0);

        $this->assertSame(StatusPrilike::Objavljeno, $prva->fresh()->status);
        $this->assertSame('claude-haiku-5-5', $prva->fresh()->predlog['model']);
        $this->assertSame(StatusPrilike::Objavljeno, $druga->fresh()->status);
        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::ANTHROPIC && $zahtev->hasHeader('x-api-key', self::KLJUC));
        Http::assertNotSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA);
    }

    #[Test]
    public function model_sa_claude_se_bira_iz_podesavanja_a_cena_iz_podesavanja(): void
    {
        config()->set('claude.model_obrade', 'claude');
        config()->set('claude.model', 'claude-sonnet-5-5');
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::ANTHROPIC => $this->claudeOdgovor(1_000_000, 100_000)]);

        // 1 milion ulaznih po 2 i 100 hiljada izlaznih po 10 = 2 + 1.
        $this->artisan('uvoz:obradi')->expectsOutput('Model: claude-sonnet-5-5. Poziva: 1. Tokeni: ulaz 1000000, izlaz 100000. Procena cene: $3,0000.')->assertExitCode(0);
        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::ANTHROPIC && $zahtev['model'] === 'claude-sonnet-5-5');
    }

    // Cene nisu u kodu: druga cena u podešavanju daje drugu procenu.
    #[Test]
    public function cena_dolazi_iz_podesavanja(): void
    {
        config()->set('claude.model_obrade', 'claude');
        config()->set('claude.cene', ['claude-haiku-5-5' => ['ulaz' => 4.0, 'izlaz' => 20.0]]);
        $this->nacrt();
        Http::fake([self::STRANA => $this->strana(), self::ANTHROPIC => $this->claudeOdgovor()]);

        // 100 hiljada ulaznih po 4 i 10 hiljada izlaznih po 20 = 0,4 + 0,2.
        $this->artisan('uvoz:obradi')->expectsOutput('Model: claude-haiku-5-5. Poziva: 1. Tokeni: ulaz 100000, izlaz 10000. Procena cene: $0,6000.')->assertExitCode(0);
    }

    // Bez ključa obrada staje kao kad Ollama ne radi, ništa se ne menja, i piše se da nije potrošeno ništa.
    #[Test]
    public function sa_claude_bez_kljuca_obrada_staje_a_nacrt_ostaje_neobradjen(): void
    {
        config()->set('claude.model_obrade', 'claude');
        config()->set('claude.kljuc', '');
        $nacrt = $this->nacrt();
        Http::fake([self::STRANA => $this->strana()]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('Claude API: ključ nije podešen (ANTHROPIC_API_KEY); ništa nije promenjeno posle ovog nacrta (obrađeno do sada: 0).')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 0. Tokeni: ulaz 0, izlaz 0. Procena cene: $0,0000.')
            ->assertExitCode(1);

        $this->assertNull($nacrt->fresh()->obradeno_at);
        Http::assertNotSent(fn (Request $zahtev) => str_contains($zahtev->url(), 'anthropic') || $zahtev->url() === self::OLLAMA);
    }

    // Preopterećen API (529): dva ponavljanja pa staje; obrađeni do tada ostaju obrađeni, a potrošnja se i tada ispiše.
    #[Test]
    public function sa_claude_preopterecenje_staje_posle_ponavljanja_a_potrosnja_se_ipak_ispise(): void
    {
        config()->set('claude.model_obrade', 'claude');
        $prvi = $this->nacrt('Prvi', 'https://a.test/prvi');
        $drugi = $this->nacrt('Drugi', 'https://a.test/drugi');
        Http::fake([
            'https://a.test/prvi' => $this->strana(),
            'https://a.test/drugi' => $this->strana(),
            self::ANTHROPIC => Http::sequence()->push(['content' => [['type' => 'text', 'text' => json_encode($this->forma())]], 'usage' => ['input_tokens' => 100_000, 'output_tokens' => 10_000]], 200)->push(['type' => 'error'], 529)->push(['type' => 'error'], 529)->push(['type' => 'error'], 529),
        ]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('Claude API je odgovorio sa HTTP 529; ništa nije promenjeno posle ovog nacrta (obrađeno do sada: 1).')
            ->expectsOutput('Model: claude-haiku-5-5. Poziva: 1. Tokeni: ulaz 100000, izlaz 10000. Procena cene: $0,0150.')
            ->assertExitCode(1);

        $this->assertSame(StatusPrilike::Objavljeno, $prvi->fresh()->status);
        $this->assertNull($drugi->fresh()->obradeno_at);
        Sleep::assertSleptTimes(2);
    }

    // Pomoćnik i „Napiši moj" ne zovu Anthropic ni kad je obrada na Claude-u: uvek lokalna Ollama.
    #[Test]
    public function pomocnik_i_napisi_moj_ostaju_na_ollami_i_kad_je_obrada_na_claude(): void
    {
        config()->set('claude.model_obrade', 'claude');
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15', 'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis']);
        $sablon = Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => "Poštovani,\n\n{tekst}\n\nS poštovanjem,\n{ime}",
        ]);
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => "Poštovani,\n\nPraksa.\n\nS poštovanjem,\nAna Anić"]]);
        }]);

        $odgovor = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();
        $pismo = $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['ime' => 'Ana Anić', 'tekst' => 'Praksa.'], 'rod' => 'zenski'])->assertOk()->getContent();

        $this->assertStringContainsString('Radnik u skladištu', (string) $odgovor);
        $this->assertStringContainsString('Praksa.', (string) $pismo);
        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA);
        Http::assertNotSent(fn (Request $zahtev) => str_contains($zahtev->url(), 'anthropic'));
    }

    // Izbor modela je jedno mesto: ModelObrade je Claude samo kad je MODEL_OBRADE „claude", inače Ollama; klase pomoćnika traže Ollamu izričito.
    // Sve osim tačnog „claude" je Ollama (greška u kucanju ne šalje strane Claude-u; na kraju obrade piše koji je model radio).
    #[Test]
    public function svaka_druga_vrednost_od_claude_je_ollama(): void
    {
        foreach (['ollama', '', 'Claude', 'CLAUDE', 'claude ', 'neki-drugi', null] as $vrednost) {
            config()->set('claude.model_obrade', $vrednost);
            $this->app->forgetInstance(ModelObrade::class);

            $this->assertInstanceOf(Ollama::class, app(ModelObrade::class), var_export($vrednost, true));
        }
    }

    #[Test]
    public function model_obrade_se_bira_po_podesavanju_a_pomocnik_trazi_ollamu(): void
    {
        $this->assertInstanceOf(Ollama::class, app(ModelObrade::class));

        config()->set('claude.model_obrade', 'claude');
        $this->app->forgetInstance(ModelObrade::class);

        $this->assertInstanceOf(Claude::class, app(ModelObrade::class));
        $this->assertSame(spl_object_id(app(ModelObrade::class)), spl_object_id(app(ModelObrade::class)));

        foreach ([PitanjeUFormular::class, PisanjeOdgovora::class, PisanjeSablona::class] as $klasa) {
            $konstruktor = (new \ReflectionClass($klasa))->getConstructor();
            $tipovi = array_map(fn (\ReflectionParameter $parametar) => (string) $parametar->getType(), $konstruktor?->getParameters() ?? []);

            $this->assertContains(Ollama::class, $tipovi, $klasa);
            $this->assertNotContains(ModelObrade::class, $tipovi, $klasa);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> MODEL_OBRADE, šta model vrati, poruka u ispisu komande */
    public static function neispravanOdgovor(): array
    {
        return [
            'Ollama nije JSON' => ['ollama', 'Evo mog mišljenja: može.', 'nije obrađen (odgovor modela nije ispravan JSON)'],
            'Claude nije JSON' => ['claude', 'Evo mog mišljenja: može.', 'nije obrađen (odgovor modela nije ispravan JSON)'],
            'Ollama bez polja' => ['ollama', '{"vazi_pravilo": "da"}', 'nije obrađen (odgovor modela nema tražena polja)'],
            'Claude bez polja' => ['claude', '{"vazi_pravilo": "da"}', 'nije obrađen (odgovor modela nema tražena polja)'],
        ];
    }

    // Paket 72/T3: poruka koju vidi Ivan ne nosi ime modela, jer odgovor može da bude i od Claude-a.
    #[Test]
    #[DataProvider('neispravanOdgovor')]
    public function poruke_o_neispravnom_odgovoru_ne_pominju_ollamu_ni_za_jedan_model(string $obrada, string $sadrzaj, string $poruka): void
    {
        config()->set('claude.model_obrade', $obrada);
        $nacrt = $this->nacrt();
        Http::fake([
            self::STRANA => $this->strana(),
            self::OLLAMA => Http::response(['message' => ['content' => $sadrzaj]]),
            self::ANTHROPIC => Http::response(['content' => [['type' => 'text', 'text' => $sadrzaj]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]),
        ]);

        $this->artisan('uvoz:obradi')->expectsOutputToContain($poruka);

        $this->assertNull($nacrt->fresh()->obradeno_at);
    }
}
