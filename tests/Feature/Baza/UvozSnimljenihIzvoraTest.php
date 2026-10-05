<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Snimci pravih odgovora pet izvora (po tri stavke, bez autora) u tests/Fixtures/rss/; internet se ne koristi.
#[Group('baza')]
class UvozSnimljenihIzvoraTest extends BazaTestCase
{
    use RefreshDatabase;

    private const SNIMCI = [
        'Fond za mlade talente' => 'fond-za-mlade-talente',
        'Erasmus+ Srbija' => 'erasmus-plus-srbija',
        'EU mogućnosti - konkursi' => 'eu-mogucnosti-konkursi',
        'Youth.rs' => 'youth-rs',
        'EU u Srbiji - konkursi' => 'eu-u-srbiji-konkursi',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));

        $odgovori = [];
        foreach (config('uvoz.izvori') as $izvor) {
            $odgovori[$izvor['adresa']] = Http::response(file_get_contents(base_path('tests/Fixtures/rss/'.self::SNIMCI[$izvor['ime']].'.xml')));
        }
        Http::fake($odgovori);
    }

    #[Test]
    public function svih_pet_izvora_iz_podesavanja_uvozi_nacrte_iz_snimaka(): void
    {
        $this->artisan('uvoz:rss')
            ->expectsOutput('Fond za mlade talente: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Erasmus+ Srbija: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('EU mogućnosti - konkursi: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Youth.rs: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('EU u Srbiji - konkursi: novih 0, preskočeno 0, greška: nema')
            ->assertExitCode(0);

        $this->assertSame(12, Prilika::query()->count());
    }

    #[Test]
    public function svaki_uvezeni_nacrt_je_ispravan_i_bez_html_a(): void
    {
        $this->artisan('uvoz:rss');

        $imena = array_keys(self::SNIMCI);

        foreach (Prilika::query()->get() as $prilika) {
            $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
            $this->assertSame(VrstaPrilike::Drugo, $prilika->vrsta);
            $this->assertContains($prilika->naziv_izvora, $imena);
            $this->assertMatchesRegularExpression('#^https://\S+$#', $prilika->link_izvora);
            $this->assertNotSame('', $prilika->naslov);
            $this->assertNotSame('', $prilika->kratak_opis);
            $this->assertLessThanOrEqual(300, mb_strlen($prilika->kratak_opis));
            $this->assertSame(0, preg_match('/[<>]/', $prilika->naslov.$prilika->kratak_opis), 'HTML u: '.$prilika->naslov);
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $prilika->naslov.$prilika->kratak_opis), 'Ćirilica u: '.$prilika->naslov);
            $this->assertNull($prilika->rok);
            $this->assertSame('2026-10-05 12:00:00', $prilika->created_at->toDateTimeString());
        }

        $this->assertSame(0, Prilika::javne()->count());
    }

    // Ogledalo: drugo pokretanje nad istim snimcima ne pravi nijedan nacrt.
    #[Test]
    public function drugo_pokretanje_nad_istim_snimcima_ne_pravi_nista_novo(): void
    {
        $this->artisan('uvoz:rss');

        $this->artisan('uvoz:rss')
            ->expectsOutput('Fond za mlade talente: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('Youth.rs: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('EU u Srbiji - konkursi: novih 0, preskočeno 0, greška: nema');

        $this->assertSame(12, Prilika::query()->count());
    }

    #[Test]
    public function svaki_izvor_iz_podesavanja_ima_svoj_snimak(): void
    {
        $this->assertSame(array_keys(self::SNIMCI), array_column(config('uvoz.izvori'), 'ime'));

        foreach (self::SNIMCI as $snimak) {
            $this->assertFileExists(base_path('tests/Fixtures/rss/'.$snimak.'.xml'));
        }
    }

    // Ogledalo: snimak Fonda je ćirilicom (neprazan skup za merilo), a nacrti iz njega su latinicom.
    #[Test]
    public function fond_pise_cirilicom_a_nacrti_iz_njega_latinicom(): void
    {
        $snimak = (string) file_get_contents(base_path('tests/Fixtures/rss/fond-za-mlade-talente.xml'));

        $this->assertSame(1, preg_match('/\p{Cyrillic}/u', $snimak));

        $this->artisan('uvoz:rss');

        $naslovi = Prilika::query()->where('naziv_izvora', 'Fond za mlade talente')->orderBy('id')->pluck('naslov')->all();

        $this->assertCount(3, $naslovi);
        $this->assertSame('Lista preliminarnih rezultata za nagrađivanje učenika srednjih škola', $naslovi[0]);
    }
}
