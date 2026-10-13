<?php

namespace Tests\Feature\Baza;

use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\EditPrilika;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\Prilika;
use App\Models\User;
use App\Support\PrikazPrilike;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 72/T3 (Ivan, PITANJA-71, 6): uz objavu admin piše „Model · <model>", ne „Ollama", i kad je objavio Claude; vrednost u bazi ostaje „ollama".
#[Group('baza')]
class AdminOznakaModelaTest extends AdminBazaTestCase
{
    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const ANTHROPIC = 'https://api.anthropic.com/v1/messages';

    private const STRANA = 'https://a.test/oglas-1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
        config()->set('claude.kljuc', 'probni-kljuc-nije-pravi-123');
        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, mixed> */
    private function forma(bool $saGrupom): array
    {
        return [
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za studente.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine',
            'grupe' => $saGrupom ? [['grupa' => 'studenti', 'citat' => 'Konkurs je otvoren za sve studente']] : [],
        ];
    }

    // Prava obrada (uvoz:obradi) sa lažnim odgovorima modela; $saGrupom=false ostavlja nacrt, jer grupa nije dokazana.
    private function obradi(string $model, bool $saGrupom = true): Prilika
    {
        config()->set('claude.model_obrade', $model);
        $nacrt = Prilika::factory()->create(['naslov' => 'Konkurs za stipendije', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => self::STRANA]);
        $sadrzaj = json_encode($this->forma($saGrupom), JSON_UNESCAPED_UNICODE);
        Http::fake([
            self::STRANA => Http::response('<html><body><h1>Konkurs</h1><p>Rok za prijavu je 20. oktobra 2026. godine.</p><p>Konkurs je otvoren za sve studente.</p></body></html>'),
            self::OLLAMA => Http::response(['message' => ['content' => $sadrzaj]]),
            self::ANTHROPIC => Http::response(['content' => [['type' => 'text', 'text' => $sadrzaj]], 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]),
        ]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        return $nacrt->fresh();
    }

    /** @return array<string, array{0: string, 1: string}> MODEL_OBRADE, model koji se upisuje u predlog */
    public static function modeli(): array
    {
        return ['Ollama' => ['ollama', 'qwen2.5:7b'], 'Claude' => ['claude', 'claude-haiku-5-5']];
    }

    #[Test]
    #[DataProvider('modeli')]
    public function na_formi_objavljene_prilike_pise_model_a_ne_ollama(string $obrada, string $model): void
    {
        $prilika = $this->obradi($obrada);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame($model, $prilika->predlog['model']);
        // Vrednost u bazi se ne menja: bez migracije.
        $this->assertSame('ollama', $prilika->getRawOriginal('objavio'));

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])
            ->assertFormSet(['objavio' => 'Model · '.$model])
            ->assertSee('Model objavljuje prilike iz uvoza, a Ivan ručno u adminu.')
            ->assertDontSee('Ollam');
    }

    #[Test]
    #[DataProvider('modeli')]
    public function na_kartici_u_spisku_pise_model_a_ne_ollama(string $obrada, string $model): void
    {
        $this->obradi($obrada);

        Livewire::test(ListPrilike::class)
            ->assertSeeInOrder(['Konkurs za stipendije', 'Objavio', 'Model · '.$model])
            ->assertDontSee('Ollam');
    }

    // Ogledalo: kad nacrt obrađen modelom objavi Ivan, piše „Ivan", bez imena modela, iako predlog i dalje nosi model.
    #[Test]
    #[DataProvider('modeli')]
    public function kad_nacrt_obradjen_modelom_objavi_ivan_pise_ivan_bez_modela(string $obrada, string $model): void
    {
        $nacrt = $this->obradi($obrada, saGrupom: false);

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->status);
        $this->assertSame($model, $nacrt->predlog['model']);

        Livewire::test(EditPrilika::class, ['record' => $nacrt->getKey()])->fillForm(['status' => StatusPrilike::Objavljeno])->call('save')->assertHasNoFormErrors();

        $this->assertSame(KoJeObjavio::Covek, $nacrt->fresh()->objavio);

        Livewire::test(EditPrilika::class, ['record' => $nacrt->getKey()])->assertFormSet(['objavio' => 'Ivan'])->assertDontSee('Model · ');
        Livewire::test(ListPrilike::class)->assertSeeInOrder(['Objavio', 'Ivan'])->assertDontSee('Model · ');
    }

    /** @return array<string, array{0: KoJeObjavio|null, 1: mixed, 2: string|null}> ko je objavio, predlog, šta admin vidi */
    public static function oznake(): array
    {
        return [
            'model sa imenom' => [KoJeObjavio::Ollama, ['model' => 'claude-haiku-5-5'], 'Model · claude-haiku-5-5'],
            'lokalni model' => [KoJeObjavio::Ollama, ['model' => 'qwen2.5:7b'], 'Model · qwen2.5:7b'],
            'predlog bez modela (ranija obrada)' => [KoJeObjavio::Ollama, ['vrsta' => 'konkurs'], 'Model'],
            'bez predloga' => [KoJeObjavio::Ollama, null, 'Model'],
            'prazno ime modela' => [KoJeObjavio::Ollama, ['model' => ''], 'Model'],
            'ime modela nije tekst' => [KoJeObjavio::Ollama, ['model' => ['x']], 'Model'],
            'Ivan, iako predlog ima model' => [KoJeObjavio::Covek, ['model' => 'claude-haiku-5-5'], 'Ivan'],
            'niko nije objavio' => [null, ['model' => 'claude-haiku-5-5'], null],
        ];
    }

    /** @param  array<string, mixed>|null  $predlog */
    #[Test]
    #[DataProvider('oznake')]
    public function oznaka_objave_je_na_jednom_mestu(?KoJeObjavio $ko, ?array $predlog, ?string $ocekivano): void
    {
        $this->assertSame($ocekivano, PrikazPrilike::objavio(new Prilika(['objavio' => $ko, 'predlog' => $predlog])));
    }
}
