<?php

namespace Tests\Feature\Baza;

use App\Enums\KoJeObjavio;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Filament\Resources\Prilike\PrilikaResource;
use App\Models\Prilika;
use App\Models\User;
use App\Support\PrikazPrilike;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

#[Group('baza')]
class AdminSpisakSuKarticeTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function spisak(): Testable
    {
        return Livewire::test(ListPrilike::class);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...[
            'naslov' => 'Konkurs za stipendije', 'status' => StatusPrilike::Nacrt, 'vrsta' => VrstaPrilike::Konkurs,
            'rok' => '2026-12-01', 'rok_stalno_otvoren' => false, 'naziv_izvora' => 'Fond za mlade talente',
            'link_izvora' => 'https://primer.rs/konkurs',
        ], ...$dopuna]);
    }

    #[Test]
    public function kartica_ima_naslov_oznake_redove_i_dugme_tim_redom(): void
    {
        $prilika = $this->prilika();
        $prilika->objavi(KoJeObjavio::Ollama);

        $this->spisak()->assertSeeInOrder([
            'Konkurs za stipendije', 'Objavljeno', 'Konkurs', 'Rok', '01.12.2026.', 'Izvor', 'Fond za mlade talente', 'Objavio', 'Ollama', 'Uredi',
        ]);
    }

    // Ogledalo: „Ivan" kad je objavio čovek.
    #[Test]
    public function objavio_pise_ivan_kad_je_objavio_covek(): void
    {
        $this->prilika()->objavi(KoJeObjavio::Covek);

        $this->spisak()->assertSeeInOrder(['Objavio', 'Ivan'])->assertDontSee('Ollama');
    }

    #[Test]
    public function rok_pise_isto_kao_na_sajtu_a_datum_kroz_prikaz(): void
    {
        $bezRoka = $this->prilika(['naslov' => 'Prva bez datuma', 'rok' => null]);
        $stalno = $this->prilika(['naslov' => 'Druga stalna', 'rok' => null, 'rok_stalno_otvoren' => true]);
        $saRokom = $this->prilika(['naslov' => 'Treća sa datumom']);

        $this->spisak()
            ->assertSee(PrikazPrilike::rok($bezRoka))
            ->assertSee(PrikazPrilike::rok($stalno))
            ->assertSee('01.12.2026.');

        $this->assertSame('Bez roka', PrikazPrilike::rok($bezRoka));
        $this->assertSame('Prijave stalno otvorene', PrikazPrilike::rok($stalno));
        $this->assertSame('Rok: 01.12.2026.', PrikazPrilike::rok($saRokom));

        // Ogledalo: kartica sa datumom ne piše ni jedan od dva teksta bez roka.
        $this->prilika(['naslov' => 'Samo datum']);
        Prilika::query()->whereIn('id', [$bezRoka->getKey(), $stalno->getKey()])->delete();

        $this->spisak()->assertDontSee('Bez roka')->assertDontSee('Prijave stalno otvorene')->assertSee('01.12.2026.');
    }

    #[Test]
    public function prazna_vrednost_ne_crta_red(): void
    {
        $this->prilika(['naziv_izvora' => null, 'link_izvora' => null]);

        $this->spisak()->assertDontSeeHtml('>Izvor<')->assertDontSeeHtml('>Objavio<')->assertSeeHtml('>Rok<');
    }

    // Ogledalo: isti zapis sa izvorom i autorom objave pokazuje oba reda.
    #[Test]
    public function popunjena_vrednost_crta_red(): void
    {
        $this->prilika()->objavi(KoJeObjavio::Ollama);

        $this->spisak()->assertSeeHtml('>Izvor<')->assertSeeHtml('>Objavio<');
    }

    #[Test]
    public function bez_naziva_izvora_red_izvor_pise_adresu_sa_koje_je_preuzeto(): void
    {
        $this->prilika(['naziv_izvora' => null, 'link_izvora' => 'https://eumogucnosti.rs/poziv']);

        $this->spisak()->assertSeeHtml('>Izvor<')->assertSee('eumogucnosti.rs');
    }

    #[Test]
    public function dugme_uredi_vodi_na_izmenu_te_prilike(): void
    {
        $prva = $this->prilika(['naslov' => 'Prva']);
        $druga = $this->prilika(['naslov' => 'Druga']);
        $adresaPrve = PrilikaResource::getUrl('edit', ['record' => $prva]);
        $adresaDruge = PrilikaResource::getUrl('edit', ['record' => $druga]);

        $this->assertNotSame($adresaPrve, $adresaDruge);

        $this->spisak()
            ->assertTableActionHasLabel('edit', 'Uredi', record: $prva)
            ->assertTableActionHasUrl('edit', $adresaPrve, record: $prva)
            ->assertTableActionHasUrl('edit', $adresaDruge, record: $druga)
            ->assertTableActionDoesNotHaveUrl('edit', $adresaDruge, record: $prva);
    }

    // Širinu meri pregledač (279 px od 311 na telefonu); ovde se čuva da dugme i dalje traži celu širinu kartice.
    #[Test]
    public function dugme_uredi_trazi_celu_sirinu_kartice(): void
    {
        $this->prilika();

        $this->spisak()->assertSeeHtml('style="width: 100%; justify-content: center;"');

        Prilika::query()->delete();

        $this->spisak()->assertDontSeeHtml('style="width: 100%; justify-content: center;"');
    }

    #[Test]
    public function spisak_su_kartice_u_mrezi_jedna_kolona_pa_tri_a_nikad_tabela(): void
    {
        $this->prilika(['naslov' => 'Prva']);
        $this->prilika(['naslov' => 'Druga']);

        $html = $this->spisak()->html();

        $this->assertSame(2, substr_count($html, 'role="listitem"'));
        $this->assertStringContainsString('--cols-default: repeat(1, minmax(0, 1fr))', $html);
        $this->assertStringContainsString('--cols-xl: repeat(3, minmax(0, 1fr))', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    #[Test]
    public function pretraga_ostaje(): void
    {
        $nadjena = $this->prilika(['naslov' => 'Radnik u skladištu']);
        $druga = $this->prilika(['naslov' => 'Konkurs za stipendije']);

        $this->spisak()
            ->assertCanSeeTableRecords([$nadjena, $druga])
            ->searchTable('skladištu')
            ->assertCanSeeTableRecords([$nadjena])
            ->assertCanNotSeeTableRecords([$druga]);
    }
}
