<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Filament\Resources\Prilike\Pages\ListPrilike;
use App\Models\Prilika;
use App\Models\User;
use Filament\Tables\Filters\SelectFilter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 86/T1: spisak prilika u adminu se sužava po izvoru, vrsti, razlogu nacrta i roku; svaki izbor ima ogledalo (drugi izbor, bez izbora).
#[Group('baza')]
class AdminSpisakPrilikaSeSuzavaIzborimaTest extends AdminBazaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-30 12:00:00');
    }

    private function spisak(): Testable
    {
        return Livewire::test(ListPrilike::class);
    }

    private function stranica(Testable $spisak): ListPrilike
    {
        $stranica = $spisak->instance();

        return $stranica instanceof ListPrilike ? $stranica : throw new \LogicException('Spisak nije strana prilika.');
    }

    /** @return array<string, string> šta izbor nudi (vrednost => natpis) */
    private function ponudjeno(Testable $spisak, string $izbor): array
    {
        $filter = $this->stranica($spisak)->getTable()->getFilter($izbor);

        return $filter instanceof SelectFilter ? $filter->getOptions() : throw new \LogicException($izbor.' nije izbor iz spiska.');
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(string $naslov, array $dopuna = []): Prilika
    {
        return Prilika::factory()->create([...['naslov' => $naslov, 'status' => StatusPrilike::Nacrt, 'vrsta' => VrstaPrilike::Drugo, 'naziv_izvora' => 'Bibija', 'rok' => null], ...$dopuna]);
    }

    #[Test]
    public function izvor_suzava_spisak_na_prilike_tog_izvora(): void
    {
        $bibija = $this->prilika('Iz Bibije', ['naziv_izvora' => 'Bibija']);
        $osvit = $this->prilika('Iz Osvita', ['naziv_izvora' => 'Osvit']);

        $this->spisak()->assertCanSeeTableRecords([$bibija, $osvit])
            ->filterTable('naziv_izvora', 'Bibija')->assertCanSeeTableRecords([$bibija])->assertCanNotSeeTableRecords([$osvit])
            ->filterTable('naziv_izvora', 'Osvit')->assertCanSeeTableRecords([$osvit])->assertCanNotSeeTableRecords([$bibija])
            ->removeTableFilter('naziv_izvora')->assertCanSeeTableRecords([$bibija, $osvit]);
    }

    // Izbor nudi samo izvore koje prilike imaju, svaki jednom, po abecedi.
    #[Test]
    public function izbor_izvora_nudi_samo_izvore_iz_baze_svaki_jednom(): void
    {
        $this->prilika('Prva', ['naziv_izvora' => 'Osvit']);
        $this->prilika('Druga', ['naziv_izvora' => 'Osvit']);
        $this->prilika('Treća', ['naziv_izvora' => 'Bibija']);
        $this->prilika('Bez izvora', ['naziv_izvora' => null]);
        $this->prilika('Prazan izvor', ['naziv_izvora' => '']);

        $ponudjeno = $this->ponudjeno($this->spisak(), 'naziv_izvora');

        $this->assertSame(['Bibija' => 'Bibija', 'Osvit' => 'Osvit'], $ponudjeno);
        $this->assertSame(['Bibija', 'Osvit'], Prilika::izvori());
    }

    #[Test]
    public function vrsta_suzava_spisak_na_prilike_te_vrste(): void
    {
        $konkurs = $this->prilika('Konkurs', ['vrsta' => VrstaPrilike::Konkurs]);
        $posao = $this->prilika('Posao', ['vrsta' => VrstaPrilike::Posao]);

        $this->spisak()->filterTable('vrsta', VrstaPrilike::Konkurs->value)->assertCanSeeTableRecords([$konkurs])->assertCanNotSeeTableRecords([$posao])
            ->filterTable('vrsta', VrstaPrilike::Posao->value)->assertCanSeeTableRecords([$posao])->assertCanNotSeeTableRecords([$konkurs]);
    }

    #[Test]
    public function zasto_je_nacrt_suzava_spisak_na_nacrte_sa_tim_razlogom(): void
    {
        $prosao = $this->prilika('Rok je prošao', ['predlog' => ['odbijeno' => 'rok je prošao']]);
        $pravilo = $this->prilika('Pravilo ne važi', ['predlog' => ['odbijeno' => 'pravilo za objavu ne važi']]);
        $neobradjen = $this->prilika('Još nije obrađen');
        $bezRazloga = $this->prilika('Obrađen bez razloga', ['predlog' => ['odbijeno' => null]]);

        $this->spisak()->assertCanSeeTableRecords([$prosao, $pravilo, $neobradjen, $bezRazloga])
            ->filterTable('zasto_je_nacrt', 'rok je prošao')->assertCanSeeTableRecords([$prosao])->assertCanNotSeeTableRecords([$pravilo, $neobradjen, $bezRazloga])
            ->filterTable('zasto_je_nacrt', 'pravilo za objavu ne važi')->assertCanSeeTableRecords([$pravilo])->assertCanNotSeeTableRecords([$prosao, $neobradjen, $bezRazloga]);
    }

    // Razlog stoji samo uz nacrt: objavljena prilika sa istim tekstom u predlogu ne ulazi, a „nema razloga" (JSON null) se ne nudi kao razlog.
    #[Test]
    public function zasto_je_nacrt_ne_hvata_objavljene_ni_obradu_bez_razloga_i_nudi_samo_stvarne_razloge(): void
    {
        $nacrt = $this->prilika('Nacrt', ['predlog' => ['odbijeno' => 'rok je prošao']]);
        $objavljena = $this->prilika('Objavljena', ['status' => StatusPrilike::Objavljeno, 'link_izvora' => 'https://a.test/1', 'predlog' => ['odbijeno' => 'rok je prošao']]);
        $this->prilika('Bez razloga', ['predlog' => ['odbijeno' => null]]);
        $this->prilika('Drugi razlog', ['predlog' => ['odbijeno' => 'nema ispravnog roka']]);
        $this->prilika('Isti razlog opet', ['predlog' => ['odbijeno' => 'rok je prošao']]);
        $this->prilika('Razlog samo kod arhivirane', ['status' => StatusPrilike::Arhivirano, 'predlog' => ['odbijeno' => 'vrsta nije potkrepljena']]);

        $spisak = $this->spisak()->filterTable('zasto_je_nacrt', 'rok je prošao');
        $spisak->assertCanSeeTableRecords([$nacrt])->assertCanNotSeeTableRecords([$objavljena]);

        $ponudjeno = $this->ponudjeno($spisak, 'zasto_je_nacrt');

        $this->assertSame(['nema ispravnog roka' => 'Nema ispravnog roka', 'rok je prošao' => 'Rok je prošao'], $ponudjeno);
        $this->assertSame(['nema ispravnog roka', 'rok je prošao'], Prilika::razloziNacrta());
    }

    // „Rok prošao" je isti uslov kao oznaka na kartici: da = rok pre danas, ne = rok danas ili kasnije, bez roka i stalno otvorene.
    #[Test]
    public function rok_prosao_deli_spisak_na_proslo_i_neproslo(): void
    {
        $prosla = $this->prilika('Rok prošao juče', ['rok' => '2026-10-29']);
        $danas = $this->prilika('Rok je danas', ['rok' => '2026-10-30']);
        $buduca = $this->prilika('Rok u novembru', ['rok' => '2026-11-15']);
        $bezRoka = $this->prilika('Bez roka');
        $stalno = $this->prilika('Stalno otvorena', ['rok' => '2026-01-01', 'rok_stalno_otvoren' => true]);

        $spisak = $this->spisak()->assertCanSeeTableRecords([$prosla, $danas, $buduca, $bezRoka, $stalno]);

        $spisak->filterTable('rok_prosao', true)->assertCanSeeTableRecords([$prosla])->assertCanNotSeeTableRecords([$danas, $buduca, $bezRoka, $stalno]);
        $spisak->filterTable('rok_prosao', false)->assertCanSeeTableRecords([$danas, $buduca, $bezRoka, $stalno])->assertCanNotSeeTableRecords([$prosla]);
        $spisak->removeTableFilter('rok_prosao')->assertCanSeeTableRecords([$prosla, $danas, $buduca, $bezRoka, $stalno]);
    }

    // Izbori se sabiraju: i status, i izvor, i vrsta.
    #[Test]
    public function izbori_se_sabiraju(): void
    {
        $trazena = $this->prilika('Tražena', ['naziv_izvora' => 'Bibija', 'vrsta' => VrstaPrilike::Konkurs, 'predlog' => ['odbijeno' => 'rok je prošao']]);
        $drugiIzvor = $this->prilika('Drugi izvor', ['naziv_izvora' => 'Osvit', 'vrsta' => VrstaPrilike::Konkurs, 'predlog' => ['odbijeno' => 'rok je prošao']]);
        $drugaVrsta = $this->prilika('Druga vrsta', ['naziv_izvora' => 'Bibija', 'vrsta' => VrstaPrilike::Posao, 'predlog' => ['odbijeno' => 'rok je prošao']]);
        $drugiRazlog = $this->prilika('Drugi razlog', ['naziv_izvora' => 'Bibija', 'vrsta' => VrstaPrilike::Konkurs, 'predlog' => ['odbijeno' => 'nema ispravnog roka']]);

        $this->spisak()
            ->filterTable('naziv_izvora', 'Bibija')->filterTable('vrsta', VrstaPrilike::Konkurs->value)->filterTable('zasto_je_nacrt', 'rok je prošao')
            ->assertCanSeeTableRecords([$trazena])->assertCanNotSeeTableRecords([$drugiIzvor, $drugaVrsta, $drugiRazlog]);
    }

    // Veza sa početne strane admina (?filters[status][value]=nacrt) i dalje radi, i sa novim izborima uz nju.
    #[Test]
    public function veza_sa_pocetne_strane_i_dalje_radi_i_sabira_se_sa_novim_izborima(): void
    {
        $nacrtBibija = $this->prilika('Nacrt iz Bibije', ['naziv_izvora' => 'Bibija']);
        $nacrtOsvit = $this->prilika('Nacrt iz Osvita', ['naziv_izvora' => 'Osvit']);
        $objavljena = $this->prilika('Objavljena iz Bibije', ['status' => StatusPrilike::Objavljeno, 'link_izvora' => 'https://a.test/1', 'naziv_izvora' => 'Bibija']);

        $samoNacrti = Livewire::withQueryParams(['filters' => ['status' => ['value' => StatusPrilike::Nacrt->value]]])->test(ListPrilike::class);
        $samoNacrti->assertCanSeeTableRecords([$nacrtBibija, $nacrtOsvit])->assertCanNotSeeTableRecords([$objavljena]);

        $samoNacrti->filterTable('naziv_izvora', 'Bibija')->assertCanSeeTableRecords([$nacrtBibija])->assertCanNotSeeTableRecords([$nacrtOsvit, $objavljena]);
    }
}
