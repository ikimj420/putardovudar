<?php

namespace Tests\Feature\Baza;

use App\Filament\Resources\Prilike\PrilikaResource;
use App\Models\Prilika;
use App\Models\User;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class AdminJeNaSrpskomLatinicomTest extends AdminBazaTestCase
{
    use VidljivTekst;

    private const ENGLESKI = [
        'Dashboard', 'Welcome', 'Sign out', 'Search', 'Create', 'Save changes', 'Cancel', 'Edit', 'New prilika',
        'Sign in', 'Email address', 'Password', 'Remember me', 'Documentation', 'Skip to content', '1 result',
    ];

    // Jezik se zadaje ovde, da test ne zavisi od jezika u lokalnom .env.
    protected function setUp(): void
    {
        $this->postaviOkruzenje('APP_LOCALE', 'sr_Latn');

        parent::setUp();
    }

    #[Test]
    public function nijedan_od_nabrojanih_engleskih_natpisa_nije_na_ekranu(): void
    {
        foreach ($this->strane() as $strana => $html) {
            foreach (self::ENGLESKI as $natpis) {
                $this->assertStringNotContainsString($natpis, $this->vidljivTekst($html), $strana.': „'.$natpis.'“ je na ekranu');
            }
        }
    }

    #[Test]
    public function nijedan_natpis_ni_naslov_kartice_nije_cirilicom(): void
    {
        foreach ($this->strane() as $strana => $html) {
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $this->vidljivTekst($html).' '.$this->naslovStrane($html)), $strana.' ima ćirilicu');
        }
    }

    // Ogledalo: isti ekrani nose srpske natpise, pa prethodne dve tvrdnje nisu zelene zato što je ekran prazan.
    #[Test]
    public function ekrani_nose_srpske_natpise_i_naslove_kartica(): void
    {
        $ocekivano = [
            'prijava' => ['Prijava - Putardo Vudar', ['Prijava', 'Lozinka', 'Prijavi se']],
            'kontrolna tabla' => ['Nadzorna tabla - Putardo Vudar', ['Nadzorna tabla', 'Dobro došli', 'Odjavi se']],
            'spisak' => ['Prilike - Putardo Vudar', ['Nova prilika', 'Pretraga', 'Uredi']],
            'dodavanje' => ['Nova prilika - Putardo Vudar', ['Nova prilika', 'Napravi', 'Odustani']],
            'izmena' => ['Izmena prilike - Putardo Vudar', ['Izmena prilike', 'Sačuvaj promene', 'Odustani']],
        ];

        $strane = $this->strane();

        $this->assertSame(array_keys($ocekivano), array_keys($strane));

        foreach ($ocekivano as $strana => [$naslov, $natpisi]) {
            $this->assertSame($naslov, $this->naslovStrane($strane[$strana]), $strana);

            foreach ($natpisi as $natpis) {
                $this->assertStringContainsString($natpis, $this->vidljivTekst($strane[$strana]), $strana.': nema „'.$natpis.'“');
            }
        }
    }

    // Filament nema srpski prevod za ova dva natpisa; nadjačani su u lang/vendor/.
    #[Test]
    public function dva_filamentova_natpisa_bez_prevoda_pisu_se_na_srpskom(): void
    {
        $tekst = $this->vidljivTekst($this->strane()['spisak']);

        $this->assertStringContainsString('Preskoči na sadržaj', $tekst);
        // Natpis pretrage i broj rezultata nisu više jedan do drugog: između njih je sada izbor statusa (paket 36).
        $this->assertStringContainsString('Pretraga', $tekst);
        $this->assertStringContainsString('1 rezultat', $tekst);
        $this->assertStringNotContainsString('Skip to content', $tekst);
        $this->assertStringNotContainsString('1 result', $tekst);
    }

    // Ogledalo: broj rezultata se sklanja za nulu, jedan, dva i pet.
    #[Test]
    public function broj_rezultata_se_pise_za_nula_jedan_dva_i_pet(): void
    {
        $this->assertSame('Nema rezultata', trans_choice('filament-tables::table.result_count', 0, ['count' => 0]));
        $this->assertSame('1 rezultat', trans_choice('filament-tables::table.result_count', 1, ['count' => 1]));
        $this->assertSame('2 rezultata', trans_choice('filament-tables::table.result_count', 2, ['count' => 2]));
        $this->assertSame('5 rezultata', trans_choice('filament-tables::table.result_count', 5, ['count' => 5]));
    }

    /** @return array<string, string> */
    private function strane(): array
    {
        $prilika = Prilika::factory()->create(['naslov' => 'Primer', 'rok' => '2026-10-05']);
        $strane = ['prijava' => $this->get(Filament::getLoginUrl())->getContent()];

        $this->actingAs(User::factory()->create(['name' => 'Probni Korisnik']));

        $strane['kontrolna tabla'] = $this->get(Filament::getUrl())->getContent();
        $strane['spisak'] = $this->get(PrilikaResource::getUrl('index'))->getContent();
        $strane['dodavanje'] = $this->get(PrilikaResource::getUrl('create'))->getContent();
        $strane['izmena'] = $this->get(PrilikaResource::getUrl('edit', ['record' => $prilika]))->getContent();

        return $strane;
    }
}
