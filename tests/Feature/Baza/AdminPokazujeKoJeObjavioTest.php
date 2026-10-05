<?php

namespace Tests\Feature\Baza;

use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\CreatePrilika;
use App\Filament\Resources\Prilike\Pages\EditPrilika;
use App\Models\Prilika;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class AdminPokazujeKoJeObjavioTest extends AdminBazaTestCase
{
    private const POMOC_OBJAVIO = 'Ollama objavljuje prilike iz uvoza, a Ivan ručno u adminu.';

    private const POMOC_RAZLOG = 'Zašto je prilika objavljena.';

    private const POMOC_PREDLOG = 'Ollama je predložila, ali kod nije prihvatio.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function izmena(Prilika $prilika): Testable
    {
        return Livewire::test(EditPrilika::class, ['record' => $prilika->getKey()]);
    }

    /** @return array<string, mixed> */
    private function predlog(array $dopuna = []): array
    {
        return [...[
            'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'razlog' => 'Konkurs za stipendije.',
            'citat' => 'Rok za prijavu je 20. oktobra 2026.', 'odbijeno' => 'rok je prošao', 'model' => 'qwen2.5:7b',
        ], ...$dopuna];
    }

    #[Test]
    public function na_prilici_koju_je_objavila_ollama_pise_ko_je_objavio_i_zasto(): void
    {
        $prilika = Prilika::factory()->create(['status' => StatusPrilike::Nacrt]);
        $prilika->forceFill(['razlog_objave' => 'Konkurs za stipendije otvoren za građane Srbije.'])->objavi(KoJeObjavio::Ollama);

        $this->izmena($prilika)
            ->assertFormFieldIsVisible('objavio')
            ->assertFormSet(['objavio' => 'Ollama', 'razlog_objave' => 'Konkurs za stipendije otvoren za građane Srbije.'])
            ->assertSee(self::POMOC_OBJAVIO)
            ->assertSee(self::POMOC_RAZLOG);
    }

    // Ogledalo: ručno objavljena prilika kaže „Ivan", a Ollama se ne pominje kao autor.
    #[Test]
    public function na_prilici_koju_je_objavio_ivan_pise_ivan(): void
    {
        $prilika = Prilika::factory()->create(['status' => StatusPrilike::Nacrt]);
        $prilika->objavi(KoJeObjavio::Covek);

        $this->izmena($prilika)
            ->assertFormSet(['objavio' => 'Ivan'])
            ->assertFormFieldIsHidden('razlog_objave');
    }

    #[Test]
    public function polja_o_objavi_se_samo_citaju_i_snimanje_ih_ne_menja(): void
    {
        $prilika = Prilika::factory()->create(['status' => StatusPrilike::Nacrt]);
        $prilika->forceFill(['razlog_objave' => 'Stvaran razlog.', 'predlog' => $this->predlog()])->objavi(KoJeObjavio::Ollama);

        $this->izmena($prilika)
            ->assertFormFieldIsDisabled('objavio')
            ->assertFormFieldIsDisabled('razlog_objave')
            ->fillForm(['naslov' => 'Promenjen naslov', 'objavio' => 'Ivan', 'razlog_objave' => 'Izmišljen razlog.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $posle = $prilika->fresh();

        $this->assertSame('Promenjen naslov', $posle->naslov);
        $this->assertSame(KoJeObjavio::Ollama, $posle->objavio);
        $this->assertSame('Stvaran razlog.', $posle->razlog_objave);
        $this->assertSame($this->predlog(), $posle->predlog);
    }

    #[Test]
    public function kad_ivan_objavi_nacrt_kroz_formu_pise_da_je_objavio_on(): void
    {
        $nacrt = Prilika::factory()->create(['status' => StatusPrilike::Nacrt, 'link_izvora' => 'https://primer.rs/konkurs']);

        $this->izmena($nacrt)->fillForm(['status' => StatusPrilike::Objavljeno])->call('save')->assertHasNoFormErrors();

        $this->assertSame(StatusPrilike::Objavljeno, $nacrt->fresh()->status);
        $this->assertSame(KoJeObjavio::Covek, $nacrt->fresh()->objavio);
        $this->izmena($nacrt)->assertFormSet(['objavio' => 'Ivan']);
    }

    // Ogledalo: izmena naslova na prilici koju je objavila Ollama ne pravi od nje Ivanovu.
    #[Test]
    public function izmena_objavljene_prilike_ne_menja_ko_ju_je_objavio(): void
    {
        $prilika = Prilika::factory()->create(['status' => StatusPrilike::Nacrt]);
        $prilika->objavi(KoJeObjavio::Ollama);

        $this->izmena($prilika)->fillForm(['naslov' => 'Drugi naslov'])->call('save');

        $this->assertSame(KoJeObjavio::Ollama, $prilika->fresh()->objavio);
    }

    #[Test]
    public function ponovna_objava_posle_vracanja_u_nacrt_je_ivanova(): void
    {
        $prilika = Prilika::factory()->create(['status' => StatusPrilike::Nacrt]);
        $prilika->objavi(KoJeObjavio::Ollama);
        $prilika->update(['status' => StatusPrilike::Nacrt]);

        $this->assertSame(KoJeObjavio::Ollama, $prilika->fresh()->objavio);

        $prilika->update(['status' => StatusPrilike::Objavljeno]);

        $this->assertSame(KoJeObjavio::Covek, $prilika->fresh()->objavio);
    }

    #[Test]
    public function prazna_polja_ne_crtaju_red(): void
    {
        $prilika = Prilika::factory()->create(['status' => StatusPrilike::Nacrt]);

        $this->izmena($prilika)
            ->assertFormFieldIsHidden('objavio')
            ->assertFormFieldIsHidden('razlog_objave')
            ->assertDontSee(self::POMOC_OBJAVIO)
            ->assertDontSee(self::POMOC_RAZLOG)
            ->assertDontSee('Predlog Ollame')
            ->assertDontSee(self::POMOC_PREDLOG);

        // Objavljena pre ovog paketa: ne zna se ko je objavio, pa se ne tvrdi ništa.
        $stara = Prilika::factory()->objavljena()->create();
        Prilika::query()->whereKey($stara->getKey())->update(['objavio' => null]);

        $this->izmena($stara)->assertFormFieldIsHidden('objavio')->assertDontSee(self::POMOC_OBJAVIO);
    }

    #[Test]
    public function nacrt_sa_predlogom_ollame_pokazuje_predlog_a_prazne_vrednosti_ne_crtaju_red(): void
    {
        $nacrt = Prilika::factory()->create(['status' => StatusPrilike::Nacrt, 'predlog' => $this->predlog()]);

        $this->izmena($nacrt)
            ->assertSee('Predlog Ollame')
            ->assertSee(self::POMOC_PREDLOG)
            ->assertFormSet([
                'predlog.vrsta' => 'Konkurs', 'predlog.rok' => '20.10.2026.',
                'predlog.razlog' => 'Konkurs za stipendije.', 'predlog.citat' => 'Rok za prijavu je 20. oktobra 2026.',
            ])
            ->assertFormFieldIsDisabled('predlog.citat');

        $bezRoka = Prilika::factory()->create(['status' => StatusPrilike::Nacrt, 'predlog' => $this->predlog(['rok' => null, 'citat' => ''])]);

        $this->izmena($bezRoka)
            ->assertSee('Predlog Ollame')
            ->assertFormFieldIsVisible('predlog.razlog')
            ->assertFormFieldIsHidden('predlog.rok')
            ->assertFormFieldIsHidden('predlog.citat');
    }

    // Ogledalo: predlog se ne pokazuje na objavljenoj prilici ni na formi za dodavanje.
    #[Test]
    public function predlog_se_ne_pokazuje_na_objavljenoj_prilici_ni_pri_dodavanju(): void
    {
        $objavljena = Prilika::factory()->create(['status' => StatusPrilike::Nacrt, 'predlog' => $this->predlog(['odbijeno' => null])]);
        $objavljena->objavi(KoJeObjavio::Ollama);

        $this->izmena($objavljena)->assertDontSee('Predlog Ollame')->assertDontSee(self::POMOC_PREDLOG);

        Livewire::test(CreatePrilika::class)
            ->assertFormFieldIsHidden('objavio')
            ->assertFormFieldIsHidden('razlog_objave')
            ->assertDontSee('Predlog Ollame')
            ->fillForm(['naslov' => 'Nova', 'vrsta' => VrstaPrilike::Posao, 'kratak_opis' => 'Opis.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Prilika::query()->where('naslov', 'Nova')->sole()->objavio);
    }
}
