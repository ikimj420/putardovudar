<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 84/T1 (Ivan, 30.10.2026.): rok bez godine koji pada više od šest meseci posle datuma objave nije siguran, pa ostaje nacrt. Povod je prava strana Youth.rs
// (snimak 30.10.2026., ime autora zamenjeno): „najkasnije do 5. decembra" u vesti od 8.12.2025. kod je čitao kao 5.12.2026.
#[Group('baza')]
class RokBezGodineDalekoOdObjaveJeNacrtTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const RECNIK = 'https://youth.rs/archives/18125';

    private const OPRAVDANO = 'rok bez godine je daleko od datuma objave';

    private const STRANA = 'https://a.test/oglas-1';

    private const GRUPA = 'Konkurs je otvoren za sve studente';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
    }

    private function snimak(string $ime): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/stranice/'.$ime));
    }

    /** @param  array<string, mixed>  $odgovor */
    private function obradi(string $adresa, string $telo, array $odgovor, ?string $objavljeno, string $danas): Prilika
    {
        $this->travelTo(Carbon::parse($danas.' 12:00:00', 'Europe/Belgrade'));
        $nacrt = Prilika::factory()->create(['naslov' => 'Poziv', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => $adresa, 'izvor_objavljen' => $objavljeno]);
        Http::fake([$adresa => Http::response($telo, 200, ['Content-Type' => 'text/html; charset=UTF-8']), self::OLLAMA => Http::response(['message' => ['content' => json_encode($odgovor, JSON_UNESCAPED_UNICODE)]])]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        return $nacrt->fresh();
    }

    /** @return array<string, mixed> */
    private function odgovorZaRecnik(): array
    {
        return json_decode($this->snimak('youth-recnik-otpora.odgovor-modela.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $dopuna
     * @return array<string, mixed>
     */
    private function odgovor(string $rok, string $citat, array $dopuna = []): array
    {
        return [...[
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za studente.', 'vrsta' => 'konkurs', 'rok' => $rok, 'citat' => $citat,
            'grupe' => [['grupa' => 'studenti', 'citat' => self::GRUPA]],
        ], ...$dopuna];
    }

    private function poziv(string $recenica): string
    {
        return '<p>Konkurs.</p><p>'.$recenica.'</p><p>'.self::GRUPA.'.</p>';
    }

    #[Test]
    public function recnik_otpora_sa_pravog_snimka_ostaje_nacrt_sa_razlogom(): void
    {
        $prilika = $this->obradi(self::RECNIK, $this->snimak('youth-recnik-otpora.html'), $this->odgovorZaRecnik(), '2025-12-08', '2026-10-30');

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNull($prilika->rok);
        $this->assertSame(self::OPRAVDANO, $prilika->predlog['odbijeno']);
        $this->assertSame('2025-12-05', $prilika->predlog['rok_modela']);
        $this->assertSame('2025-12-05', $prilika->predlog['rok']);
        $this->assertFalse(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    // Ogledalo na istom snimku i istom odgovoru modela: kad je vest objavljena u junu, „5. decembra" je prvi takav dan u roku od šest meseci, pa prolazi.
    #[Test]
    public function ista_strana_sa_objavom_u_junu_se_objavljuje_sa_rokom_u_decembru(): void
    {
        $prilika = $this->obradi(self::RECNIK, $this->snimak('youth-recnik-otpora.html'), $this->odgovorZaRecnik(), '2026-06-10', '2026-10-30');

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-12-05', $prilika->rok->toDateString());
        $this->assertNull($prilika->predlog['odbijeno']);
        $this->assertTrue(Prilika::javne()->whereKey($prilika->id)->exists());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> objavljeno, danas, rok modela, rečenica na strani, citat, očekivani rok */
    public static function rokoviKojiProlaze(): array
    {
        return [
            'decembar → januar' => ['2025-12-08', '2025-12-10', '2026-01-05', 'Prijave su otvorene do 5. januara.', 'Prijave su otvorene do 5. januara', '2026-01-05'],
            'tačno šest meseci' => ['2026-01-15', '2026-01-20', '2026-07-15', 'Prijave su otvorene do 15. jula.', 'Prijave su otvorene do 15. jula', '2026-07-15'],
            'kraj meseca: šest meseci od 31. avgusta je 28. februar' => ['2026-08-31', '2026-09-05', '2027-02-28', 'Prijave su otvorene do 28. februara.', 'Prijave su otvorene do 28. februara', '2027-02-28'],
        ];
    }

    #[Test]
    #[DataProvider('rokoviKojiProlaze')]
    public function rok_u_okviru_od_sest_meseci_prolazi(string $objavljeno, string $danas, string $rokModela, string $recenica, string $citat, string $ocekivano): void
    {
        $prilika = $this->obradi(self::STRANA, $this->poziv($recenica), $this->odgovor($rokModela, $citat), $objavljeno, $danas);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame($ocekivano, $prilika->rok->toDateString());
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> objavljeno, danas, rok modela, rečenica na strani, citat */
    public static function rokoviKojiNeProlaze(): array
    {
        return [
            'dan posle šest meseci' => ['2026-01-15', '2026-01-20', '2026-07-16', 'Prijave su otvorene do 16. jula.', 'Prijave su otvorene do 16. jula'],
            'kraj meseca: dan posle 28. februara' => ['2026-08-31', '2026-09-05', '2027-03-01', 'Prijave su otvorene do 1. marta.', 'Prijave su otvorene do 1. marta'],
            'skoro godinu dana' => ['2025-12-08', '2025-12-10', '2026-12-05', 'Prijave su otvorene do 5. decembra.', 'Prijave su otvorene do 5. decembra'],
        ];
    }

    #[Test]
    #[DataProvider('rokoviKojiNeProlaze')]
    public function rok_dalje_od_sest_meseci_ostaje_nacrt_sa_razlogom(string $objavljeno, string $danas, string $rokModela, string $recenica, string $citat): void
    {
        $prilika = $this->obradi(self::STRANA, $this->poziv($recenica), $this->odgovor($rokModela, $citat), $objavljeno, $danas);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertNull($prilika->rok);
        $this->assertSame(self::OPRAVDANO, $prilika->predlog['odbijeno']);
    }

    // Ogledalo: pravilo je za rok bez godine. Kad godina piše na strani, rok daleko od objave je običan rok i prolazi.
    #[Test]
    public function rok_sa_godinom_na_strani_prolazi_ma_koliko_bio_daleko_od_objave(): void
    {
        $prilika = $this->obradi(self::STRANA, $this->poziv('Prijave su otvorene do 5. decembra 2026. godine.'), $this->odgovor('2026-12-05', 'Prijave su otvorene do 5. decembra 2026. godine'), '2025-12-08', '2026-10-30');

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-12-05', $prilika->rok->toDateString());
    }

    // Ogledalo: razlog „daleko od datuma objave" ne sme da potisne ostale razloge, pa bez datuma objave ostaje stari razlog.
    #[Test]
    public function bez_datuma_objave_ostaje_stari_razlog(): void
    {
        $prilika = $this->obradi(self::STRANA, $this->poziv('Prijave su otvorene do 5. decembra.'), $this->odgovor('2026-12-05', 'Prijave su otvorene do 5. decembra'), null, '2026-10-30');

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
    }
}
