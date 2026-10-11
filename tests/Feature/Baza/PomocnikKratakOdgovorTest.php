<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Services\Pomocnik\Pomocnik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 65/T2 (Ivan, 10.10.2026.: „odgovori su i predugi i previše mašinski"): model dobija uputstvo da odgovori u jednoj ili dve kratke rečenice, toplo, na „ti",
// a detalje ostavi karticama izvora. Ostala pravila uputstva ostaju kakva su bila.
#[Group('baza')]
class PomocnikKratakOdgovorTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    /** @var array<string, mixed> */
    private array $formular = ['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []];

    private string $odgovorModela = 'Našla sam posao u skladištu u Nišu.';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15', 'kratak_opis' => 'Puno radno vreme.', 'opis' => 'Rad u skladištu.']);
        // Http::fake ostaje na prvom postavljenom odgovoru, pa se postavlja jednom, a odgovori se menjaju kroz svojstva.
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode($this->formular)]])
                : Http::response(['message' => ['content' => $this->odgovorModela]]);
        }]);
    }

    private function uputstvoZaOdgovor(): string
    {
        $zahtev = Http::recorded(fn (Request $zahtev) => ! array_key_exists('format', $zahtev->data()))->first();

        $this->assertNotNull($zahtev, 'model je pozvan za odgovor');

        return $zahtev[0]->data()['messages'][0]['content'];
    }

    #[Test]
    public function uputstvo_trazi_jednu_ili_dve_kratke_recenice_toplo_na_ti_a_detalje_ostavlja_karticama(): void
    {
        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');
        $uputstvo = $this->uputstvoZaOdgovor();

        $this->assertSame('Našla sam posao u skladištu u Nišu.', $odgovor->tekst);
        $this->assertStringContainsString('u jednoj ili dve kratke rečenice', $uputstvo);
        $this->assertStringContainsString('Reci šta si našao i za koga je.', $uputstvo);
        $this->assertStringContainsString('Ne prepričavaj rok, mesto ni uslove: oni su u karticama ispod odgovora.', $uputstvo);
        $this->assertStringContainsString('Piši toplo i jednostavno, na ti, kao drug koji pomaže, kratkim rečenicama.', $uputstvo);
        $this->assertStringContainsString('Počni sa rečima „Našao sam“ i reci o čemu su prilike. Ne piši koliko ih ima.', $uputstvo);
        // Ogledalo: stara granica od tri rečenice je otišla, a pravila provere nisu.
        $this->assertStringNotContainsString('najviše tri rečenice', $uputstvo);
        $this->assertStringContainsString('Ne navodi linkove i ne dodaj ništa iz sopstvenog znanja.', $uputstvo);
        $this->assertStringContainsString('Datum piši samo ako piše u prilikama, vodičima ili organizacijama.', $uputstvo);
        $this->assertStringContainsString('reci samo da nemaš podatak.', $uputstvo);
    }

    // Model uputstvo ne drži (izmereno), pa kod odseca: nabrajanje i sve preko dve rečenice. Izvori ostaju, jer ih sklapa kod iz zapisa.
    #[Test]
    public function odgovor_modela_sa_nabrajanjem_stize_skracen_a_izvori_ostaju(): void
    {
        $this->odgovorModela = 'Našao sam posao u Nišu:

- Radnik u skladištu, Niš, do 15.11.2026.
- Puno radno vreme.';

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame('Našao sam posao u Nišu.', $odgovor->tekst);
        $this->assertFalse($odgovor->jeIzSablona());
        $this->assertSame(['Radnik u skladištu'], array_map(fn ($izvor) => $izvor->naslov, $odgovor->izvori));

        $this->odgovorModela = 'Ima posla u Nišu. Za radnika u skladištu. Puno radno vreme. Rok je 15. novembra 2026.';

        $this->assertSame('Ima posla u Nišu. Za radnika u skladištu.', app(Pomocnik::class)->odgovori('Ima li posla u Nišu?')->tekst);
    }

    // Skraćuje se pre provere: izmišljen datum u trećoj rečenici se odseca, a u prvoj dve i dalje daje šablon iz zapisa.
    #[Test]
    public function provera_radi_nad_skracenim_odgovorom(): void
    {
        $this->odgovorModela = 'Ima posla u Nišu. Za radnika u skladištu. Prijave su do 20. oktobra 2026.';
        $odsecen = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertFalse($odsecen->jeIzSablona());
        $this->assertSame('Ima posla u Nišu. Za radnika u skladištu.', $odsecen->tekst);

        $this->odgovorModela = 'Prijave su do 20. oktobra 2026. Ima posla u Nišu. Za radnika u skladištu.';
        $izmisljen = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertTrue($izmisljen->jeIzSablona());
        $this->assertSame('datum kog nema u zapisima', $izmisljen->razlogSablona);
    }

    // Kad je u kontekstu kalendar, uputstvo o kratkom odgovoru je i dalje tu, uz pravila o kalendaru.
    #[Test]
    public function uputstvo_o_kratkom_odgovoru_stoji_i_uz_kalendar(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Radnik stipendija', 'otvoreno_od' => '2025-12-22', 'otvoreno_do' => '2026-01-26']);
        $this->formular = ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => ['radnik']];
        $this->odgovorModela = 'Prošli put je bila otvorena od 22.12.2025. do 26.01.2026.';

        app(Pomocnik::class)->odgovori('Kada se otvara radnik?');
        $uputstvo = $this->uputstvoZaOdgovor();

        $this->assertStringContainsString('u jednoj ili dve kratke rečenice', $uputstvo);
        $this->assertStringContainsString('Kalendar kaže samo šta je bilo prošli put', $uputstvo);
        // „Našao sam da se otvara" bi rekao budućnost: uz kalendar red „Počni sa rečima" ne ide, a ostala pravila ostaju.
        $this->assertStringNotContainsString('Počni sa rečima', $uputstvo);
        $this->assertStringContainsString('Ne navodi linkove i ne dodaj ništa iz sopstvenog znanja.', $uputstvo);
    }
}
