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
use Tests\Podrska\LazniClaude;

// Paket 72/T3 (Ivan, PITANJA-71, 6): uz objavu admin piše „Model · <model>", ne „Ollama", i kad je objavio Claude; vrednost u bazi ostaje „ollama".
#[Group('baza')]
class AdminOznakaModelaTest extends AdminBazaTestCase
{
    private const CLAUDE = LazniClaude::ADRESA;

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
            self::CLAUDE => LazniClaude::odgovor($sadrzaj),
        ]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        return $nacrt->fresh();
    }

    /** @return array<string, array{0: string, 1: string}> MODEL_OBRADE, model koji se upisuje u predlog */
    public static function modeli(): array
    {
        return ['Claude' => ['claude', 'claude-haiku-5-5']];
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

    // Zapisi koje je pre paketa 100 objavila Ollama ostaju u bazi sa „ollama" i modelom u predlogu; admin ih i dalje piše kao „Model · <model>", a reč Ollama ne ispisuje.
    #[Test]
    public function zapis_koji_je_objavila_ollama_pise_model_a_ne_ollama(): void
    {
        $prilika = Prilika::factory()->create(['naslov' => 'Stara prilika', 'status' => StatusPrilike::Nacrt, 'predlog' => ['model' => 'qwen2.5:7b', 'odbijeno' => null]]);
        $prilika->objavi(KoJeObjavio::Model);

        $this->assertSame('ollama', $prilika->fresh()->getRawOriginal('objavio'));
        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])->assertFormSet(['objavio' => 'Model · qwen2.5:7b'])->assertDontSee('Ollam');
        Livewire::test(ListPrilike::class)->assertSeeInOrder(['Stara prilika', 'Objavio', 'Model · qwen2.5:7b'])->assertDontSee('Ollam');
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
            'model sa imenom' => [KoJeObjavio::Model, ['model' => 'claude-haiku-5-5'], 'Model · claude-haiku-5-5'],
            'lokalni model' => [KoJeObjavio::Model, ['model' => 'qwen2.5:7b'], 'Model · qwen2.5:7b'],
            'predlog bez modela (ranija obrada)' => [KoJeObjavio::Model, ['vrsta' => 'konkurs'], 'Model'],
            'bez predloga' => [KoJeObjavio::Model, null, 'Model'],
            'prazno ime modela' => [KoJeObjavio::Model, ['model' => ''], 'Model'],
            'ime modela nije tekst' => [KoJeObjavio::Model, ['model' => ['x']], 'Model'],
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

    // Paket 73/T1 (Ivan, PITANJA-72, 1): isto i na nacrtu; odeljak piše „Predlog modela · <model>", a nigde ne piše „Ollama".
    #[Test]
    #[DataProvider('modeli')]
    public function na_nacrtu_pise_predlog_modela_a_ne_ollame(string $obrada, string $model): void
    {
        $nacrt = $this->obradi($obrada, saGrupom: false);

        $this->assertSame(StatusPrilike::Nacrt, $nacrt->status);

        Livewire::test(EditPrilika::class, ['record' => $nacrt->getKey()])
            ->assertSee('Predlog modela · '.$model)
            ->assertSee('Model je predložio, ali kod nije prihvatio.')
            ->assertFormSet(['predlog.vrsta' => 'Konkurs'])
            ->assertDontSee('Ollam');

        // Spisak nacrta isto ne pominje Ollamu.
        Livewire::test(ListPrilike::class)->assertSee($nacrt->naslov)->assertDontSee('Ollam');
    }

    // Ogledalo: objavljena prilika nema odeljak sa predlogom, pa ni njegov naslov.
    #[Test]
    #[DataProvider('modeli')]
    public function na_objavljenoj_prilici_nema_predloga_modela(string $obrada, string $model): void
    {
        $prilika = $this->obradi($obrada);

        Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()])->assertDontSee('Predlog modela')->assertDontSee('Model je predložio');
    }

    /** @return array<string, array{0: mixed, 1: string|null}> predlog, ime modela koje admin piše uz predlog */
    public static function modeliPredloga(): array
    {
        return [
            'lokalni model' => [['model' => 'qwen2.5:7b'], 'qwen2.5:7b'],
            'Claude' => [['model' => 'claude-haiku-5-5'], 'claude-haiku-5-5'],
            'predlog bez modela (ranija obrada)' => [['vrsta' => 'konkurs'], null],
            'bez predloga' => [null, null],
            'prazno ime modela' => [['model' => ''], null],
            'ime modela nije tekst' => [['model' => ['x']], null],
        ];
    }

    /** @param  array<string, mixed>|null  $predlog */
    #[Test]
    #[DataProvider('modeliPredloga')]
    public function ime_modela_uz_predlog_je_na_jednom_mestu(?array $predlog, ?string $ocekivano): void
    {
        $this->assertSame($ocekivano, PrikazPrilike::modelPredloga(new Prilika(['predlog' => $predlog])));
    }
}
