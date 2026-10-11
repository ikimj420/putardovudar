<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\OdgovorPomocnika;
use App\Services\Pomocnik\Pomocnik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 65/T3 (ZA-KASNIJE-POMOCNIK.md, tačka 2): pitanje samo sa vrstom („stipendije"), a otvorenih prilika te vrste nema, ne daje „nemam podatak" nego
// „Trenutno nema otvorenih stipendija. Dok čekaš, ovo ti može pomoći da budeš spreman:", pa vodiče čiji naslov ima tu reč i stavke kalendara sa tom rečju.
#[Group('baza')]
class PomocnikSamoVrstaTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    /** @var array<string, mixed> */
    private array $formular = ['vrsta' => 'stipendija', 'grad' => null, 'kome' => null, 'kljucne_reci' => []];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
        // Http::fake ostaje na prvom postavljenom odgovoru, pa se postavlja jednom, a formular se menja kroz svojstvo.
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode($this->formular)]])
                : Http::response(['message' => ['content' => 'Našao sam jednu stipendiju.']]);
        }]);
    }

    private function pitaj(string $pitanje = 'stipendije'): OdgovorPomocnika
    {
        return app(Pomocnik::class)->odgovori($pitanje);
    }

    private function pozivaModela(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA)->count();
    }

    /** @return list<string> */
    private function naslovi(OdgovorPomocnika $odgovor): array
    {
        return array_map(fn ($izvor) => $izvor->naslov, $odgovor->izvori);
    }

    #[Test]
    public function bez_otvorenih_stipendija_odgovor_je_coworkova_recenica_a_uz_nju_vodici_i_kalendar_sa_tom_recju(): void
    {
        // Ne ulaze: stipendija kojoj je rok prošao, nacrt, posao koji je otvoren (druga vrsta).
        Prilika::factory()->objavljena()->create(['naslov' => 'Stipendija kojoj je prošao rok', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-09-30']);
        Prilika::factory()->create(['naslov' => 'Nacrt stipendije', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-12-01']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-01']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za stipendiju']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV']);
        Vodic::factory()->create(['naslov' => 'Stipendija: nacrt vodiča', 'status' => StatusObjave::Nacrt]);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Stipendija Dositeja', 'otvoreno_od' => '2025-12-22', 'otvoreno_do' => '2026-01-26']);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Erasmus program', 'ko_daje' => 'Evropska unija']);
        KalendarStavka::factory()->create(['naziv' => 'Stipendija iz nacrta', 'status' => StatusObjave::Nacrt]);

        $odgovor = $this->pitaj();

        $this->assertSame('Trenutno nema otvorenih stipendija. Dok čekaš, ovo ti može pomoći da budeš spreman:', $odgovor->tekst);
        $this->assertSame(['Kako se prijaviti za stipendiju', 'Stipendija Dositeja'], $this->naslovi($odgovor));
        $this->assertTrue($odgovor->izvori[0]->jeVodic);
        $this->assertTrue($odgovor->izvori[1]->jeKalendar);
        // Samo ono što piše u kalendaru: kad je bilo otvoreno prošli put.
        $this->assertSame('Prošli put: od 22.12.2025. do 26.01.2026.', $odgovor->izvori[1]->rok);
        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertFalse($odgovor->odbijeno);
        $this->assertSame(1, $this->pozivaModela(), 'model je pozvan samo za formular');
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> vrsta, očekivana rečenica, naslov vodiča koji ima reč, naslov koji nema */
    public static function vrste(): array
    {
        return [
            'posao' => ['posao', 'Trenutno nema otvorenih poslova.', 'Kako se prijaviti za posao ili praksu', 'Kako napisati prvi CV'],
            'praksa' => ['praksa', 'Trenutno nema otvorenih praksi.', 'Kako se prijaviti za posao ili praksu', 'Kako napisati prvi CV'],
            'stipendija' => ['stipendija', 'Trenutno nema otvorenih stipendija.', 'Kako se prijaviti za stipendiju', 'Kako napisati prvi CV'],
            'konkurs' => ['konkurs', 'Trenutno nema otvorenih konkursa.', 'Kako razumeti uslove konkursa', 'Kako napisati prvi CV'],
            'obuka' => ['obuka', 'Trenutno nema otvorenih obuka.', 'Obuka za rad na računaru', 'Kako napisati prvi CV'],
        ];
    }

    // Isti obrazac za sve vrste: tačna množina u genitivu i vodič čiji naslov ima reč te vrste.
    #[Test]
    #[DataProvider('vrste')]
    public function isti_obrazac_vazi_za_svaku_vrstu(string $vrsta, string $nema, string $vodicSaReci, string $vodicBezReci): void
    {
        $this->formular['vrsta'] = $vrsta;
        Vodic::factory()->objavljen()->create(['naslov' => $vodicSaReci]);
        Vodic::factory()->objavljen()->create(['naslov' => $vodicBezReci]);

        $odgovor = $this->pitaj(['posao' => 'posao', 'praksa' => 'praksa', 'stipendija' => 'stipendije', 'konkurs' => 'konkursi', 'obuka' => 'obuke'][$vrsta]);

        $this->assertSame($nema.' '.Pomocnik::DOK_CEKAS, $odgovor->tekst);
        $this->assertSame([$vodicSaReci], $this->naslovi($odgovor));
    }

    // Bez ijednog vodiča i stavke kalendara rečenica o pripremi bi obećala kartice kojih nema: stoji samo „nema otvorenih".
    #[Test]
    public function bez_vodica_i_kalendara_ostaje_samo_rec_da_nema_otvorenih(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV']);

        $odgovor = $this->pitaj();

        $this->assertSame('Trenutno nema otvorenih stipendija.', $odgovor->tekst);
        $this->assertSame([], $odgovor->izvori);
        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertSame(1, $this->pozivaModela());
    }

    // Ima otvorenih prilika te vrste: ništa se ne menja, model piše odgovor iz njih.
    #[Test]
    public function sa_otvorenom_stipendijom_pitanje_ide_uobicajenim_putem(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Stipendija opštine Bor', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-12-01']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za stipendiju']);

        $odgovor = $this->pitaj();

        $this->assertSame('Našao sam jednu stipendiju.', $odgovor->tekst);
        $this->assertSame(['Stipendija opštine Bor'], $this->naslovi($odgovor));
        $this->assertSame(2, $this->pozivaModela());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> formular, pitanje */
    public static function nijeSamoVrsta(): array
    {
        return [
            'vrsta i grad' => [['vrsta' => 'stipendija', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []], 'stipendije u Nišu'],
            'vrsta i reč' => [['vrsta' => 'stipendija', 'grad' => null, 'kome' => null, 'kljucne_reci' => ['frizere']], 'stipendije za frizere'],
            'vrsta i kome' => [['vrsta' => 'stipendija', 'grad' => null, 'kome' => 'žene', 'kljucne_reci' => []], 'stipendije za žene'],
            'vrsta i grupa' => [['vrsta' => 'stipendija', 'grad' => null, 'kome' => null, 'kljucne_reci' => []], 'stipendije za Rome'],
            'bez vrste' => [['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], 'stipendije'],
            'vrsta drugo' => [['vrsta' => 'drugo', 'grad' => null, 'kome' => null, 'kljucne_reci' => []], 'ostalo'],
            'vrsta koju je model samo pogodio' => [['vrsta' => 'stipendija', 'grad' => null, 'kome' => null, 'kljucne_reci' => []], 'pomoć za školarinu'],
        ];
    }

    // Ogledalo: sve što nije samo vrsta ostaje „nemam podatak" kao ranije, a vodiči i kalendar se ne dodaju.
    #[Test]
    #[DataProvider('nijeSamoVrsta')]
    public function pitanje_koje_nije_samo_vrsta_ostaje_nemam_podatak(array $formular, string $pitanje): void
    {
        $this->formular = $formular;
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za stipendiju']);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Stipendija Dositeja']);

        $odgovor = $this->pitaj($pitanje);

        $this->assertSame(Pomocnik::NEMAM_PODATAK, $odgovor->tekst, $pitanje);
        $this->assertSame([], $odgovor->izvori);
        $this->assertTrue($odgovor->nemaPodatak);
    }

    // „Drugo" nema reč kojom bi se prepoznalo: provera ga odbija i sama, a ne samo preko toga što vrsta nije izrečena.
    #[Test]
    public function vrsta_drugo_nikad_nije_samo_vrsta(): void
    {
        $jeSamoVrsta = new ReflectionMethod(Pomocnik::class, 'jeSamoVrsta');

        $this->assertFalse($jeSamoVrsta->invoke(app(Pomocnik::class), new Formular(VrstaPrilike::Drugo, vrstaJePomenuta: true)));
        // Ogledalo: ista provera prihvata stipendiju sa istim formularom.
        $this->assertTrue($jeSamoVrsta->invoke(app(Pomocnik::class), new Formular(VrstaPrilike::Stipendija, vrstaJePomenuta: true)));
    }

    // Traženje da se nešto napiše („napiši mi molbu za stipendiju") nije pitanje o prilikama: nudi se šablon ili kaže da ga nema, kao i pre.
    #[Test]
    public function trazenje_da_se_nesto_napise_ne_ide_ovim_putem(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za stipendiju']);

        $odgovor = $this->pitaj('napiši mi molbu za stipendiju');

        $this->assertSame(Pomocnik::NEMA_SABLONA, $odgovor->tekst);
    }

    // Na strani: rečenica sa dvotačkom, ispod nje kartice vodiča i kalendara, bez roka; isto u prozoru.
    #[Test]
    public function strana_i_prozor_pokazuju_recenicu_i_kartice(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za stipendiju']);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Stipendija Dositeja', 'otvoreno_od' => '2025-12-22', 'otvoreno_do' => '2026-01-26']);

        foreach ([$this, $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])] as $zahtev) {
            $tekst = $this->vidljivTekst($zahtev->post(route('pomocnik.pitaj'), ['pitanje' => 'stipendije'])->assertOk()->getContent());

            $this->assertStringContainsString('Trenutno nema otvorenih stipendija. Dok čekaš, ovo ti može pomoći da budeš spreman:', $tekst);
            $this->assertStringContainsString('Kako se prijaviti za stipendiju', $tekst);
            $this->assertStringContainsString('Stipendija Dositeja', $tekst);
            $this->assertStringContainsString('Prošli put: od 22.12.2025. do 26.01.2026.', $tekst);
            $this->assertStringNotContainsString(Pomocnik::NEMAM_PODATAK, $tekst);
        }
    }
}
