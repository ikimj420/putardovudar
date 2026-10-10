<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\StatusPrilike;
use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\Prikaz;
use App\Support\PrikazPocetne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57 T6: četiri pločice na početnoj sa brojevima iz baze (javne prilike, objavljeni vodiči, objavljene organizacije, meseci sa javnim stavkama kalendara);
// svaka je u boji svoje strane i vodi na nju. Nacrt i ono čemu je prošao rok se ne broji.
#[Group('baza')]
class PlocicePocetneTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{broj: int, tekst: string, adresa: string}> strana => pločica */
    private function plocice(): array
    {
        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        preg_match_all('#<li><a class="plocica" data-strana="([a-z]+)" href="([^"]+)"><b>(\d+)</b> <span>([^<]+)</span></a></li>#', $html, $nalazi, PREG_SET_ORDER);

        $plocice = [];
        foreach ($nalazi as [, $strana, $adresa, $broj, $tekst]) {
            $plocice[$strana] = ['broj' => (int) $broj, 'tekst' => $tekst, 'adresa' => $adresa];
        }

        return $plocice;
    }

    #[Test]
    public function cetiri_plocice_vode_na_svoje_strane_redom(): void
    {
        $plocice = $this->plocice();

        $this->assertSame(['prilike', 'vodici', 'organizacije', 'kalendar'], array_keys($plocice));
        $this->assertSame(route('prilike.index'), $plocice['prilike']['adresa']);
        $this->assertSame(route('vodici.index'), $plocice['vodici']['adresa']);
        $this->assertSame(route('organizacije.index'), $plocice['organizacije']['adresa']);
        $this->assertSame(route('kalendar.index'), $plocice['kalendar']['adresa']);
        $this->assertStringContainsString('<ul class="plocice" aria-label="'.PrikazPocetne::PLOCICE_NAZIV.'">', $this->get(route('pocetna'))->getContent());
    }

    // Prazna baza daje nule, pa merilo ima šta da poveća.
    #[Test]
    public function bez_podataka_su_nule(): void
    {
        $plocice = $this->plocice();

        $this->assertSame([0, 0, 0, 0], array_column($plocice, 'broj'));
        $this->assertSame('otvorenih prilika', $plocice['prilike']['tekst']);
    }

    // Broje se samo javne prilike (nacrt i prošao rok ne), objavljeni vodiči i objavljene organizacije.
    #[Test]
    public function brojevi_su_samo_ono_sto_je_javno(): void
    {
        Prilika::factory()->objavljena()->count(2)->create(['rok' => '2026-11-15']);
        Prilika::factory()->objavljena()->create(['rok' => '2026-10-10']);
        Prilika::factory()->objavljena()->create(['rok' => null, 'rok_stalno_otvoren' => true]);
        Prilika::factory()->create(['status' => StatusPrilike::Nacrt, 'rok' => '2026-11-15']);
        Prilika::factory()->objavljena()->create(['rok' => '2026-10-09']);
        Vodic::factory()->objavljen()->count(3)->create();
        Vodic::factory()->count(2)->create(['status' => StatusObjave::Nacrt]);
        Organizacija::factory()->objavljena()->count(5)->create();
        Organizacija::factory()->count(4)->create(['status' => StatusObjave::Nacrt]);

        $plocice = $this->plocice();

        $this->assertSame(4, $plocice['prilike']['broj']);
        $this->assertSame(3, $plocice['vodici']['broj']);
        $this->assertSame(5, $plocice['organizacije']['broj']);
        $this->assertSame(0, $plocice['kalendar']['broj']);
    }

    // Meseci sa javnim stavkama: dve stavke u istom mesecu su jedan mesec; nacrt i stavka čiji kraj nije prošao se ne broje.
    #[Test]
    public function kalendar_broji_mesece_a_ne_stavke(): void
    {
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-01-26']);
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-01-10']);
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2025-06-08']);
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-02-19']);
        KalendarStavka::factory()->create(['status' => StatusObjave::Nacrt, 'otvoreno_do' => '2026-03-03']);
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-10-10']);
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-12-12']);

        $this->assertSame(3, $this->plocice()['kalendar']['broj']);
        $this->assertSame(3, KalendarStavka::brojMesecaSaStavkama());
        // Isti broj je i na strani kalendara (toliko odeljaka).
        $this->assertSame(3, substr_count($this->get(route('kalendar.index'))->getContent(), '<h2 class="naslov-izvora">'));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function oblici(): array
    {
        return [
            'nula' => [0, 'otvorenih prilika'], 'jedan' => [1, 'otvorena prilika'], 'dva' => [2, 'otvorene prilike'], 'četiri' => [4, 'otvorene prilike'],
            'pet' => [5, 'otvorenih prilika'], 'jedanaest' => [11, 'otvorenih prilika'], 'dvanaest' => [12, 'otvorenih prilika'], 'četrnaest' => [14, 'otvorenih prilika'],
            'dvadeset jedan' => [21, 'otvorena prilika'], 'dvadeset dva' => [22, 'otvorene prilike'], 'dvadeset pet' => [25, 'otvorenih prilika'], 'sto jedanaest' => [111, 'otvorenih prilika'],
        ];
    }

    #[Test]
    #[DataProvider('oblici')]
    public function oblik_reci_prati_broj(int $broj, string $ocekivano): void
    {
        $this->assertSame($ocekivano, Prikaz::oblik($broj, PrikazPocetne::PLOCICA_PRILIKE));
    }

    // Stil: pločica je u boji svoje strane; brojevi su tamnije nijanse narandžaste i zlatne (veliki tekst), ljubičasta i bobica su cele.
    #[Test]
    public function stil_boji_plocicu_bojom_svoje_strane(): void
    {
        $stil = $this->stilBezKomentara();

        foreach (['prilike' => 'narandzasta-tamna', 'vodici' => 'ljubicasta', 'organizacije' => 'bobica', 'kalendar' => 'zlato-tamno'] as $strana => $boja) {
            $this->assertMatchesRegularExpression('/\n\.plocica\[data-strana="'.$strana.'"\] b \{\s*color: var\(--'.$boja.'\);/', $stil, $strana);
        }
        foreach (['prilike' => 'narandzasta', 'vodici' => 'ljubicasta', 'organizacije' => 'bobica', 'kalendar' => 'zlato'] as $strana => $boja) {
            $this->assertMatchesRegularExpression('/\n\.plocica\[data-strana="'.$strana.'"\]::after \{\s*background: var\(--'.$boja.'\);/', $stil, $strana);
        }
        $this->assertMatchesRegularExpression('/\n\.plocice \{[^}]*grid-template-columns: repeat\(2, 1fr\);/', $stil);
        $this->assertMatchesRegularExpression('/@media \(min-width: 900px\) \{\s*\.plocice \{\s*grid-template-columns: repeat\(4, 1fr\);/', $stil);

        // Broj je veliki tekst (34 px, debeo): za njega važi 3 : 1, a sve boje brojeva ga ispunjavaju na beloj pločici; ogledalo: sirova narandžasta ne bi.
        $this->assertMatchesRegularExpression('/\n\.plocica b \{[^}]*font-size: 34px;[^}]*font-weight: 800;/', $stil);
        foreach (['narandzasta-tamna', 'ljubicasta', 'bobica', 'zlato-tamno'] as $boja) {
            $this->assertGreaterThanOrEqual(3.0, $this->kontrast($this->hex($boja), '#ffffff'), $boja);
        }
        $this->assertLessThan(3.0, $this->kontrast($this->hex('zlato'), '#ffffff'));
    }

    private function hex(string $naziv): string
    {
        preg_match('/--'.preg_quote($naziv, '/').': (#[0-9a-f]{6});/', $this->stilBezKomentara(), $nalaz);

        return $nalaz[1] ?? '';
    }

    private function osvetljenost(string $boja): float
    {
        [$r, $g, $b] = array_map(fn (string $c) => (hexdec($c) / 255.0) <= 0.03928 ? (hexdec($c) / 255.0) / 12.92 : (((hexdec($c) / 255.0) + 0.055) / 1.055) ** 2.4, str_split(ltrim($boja, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function kontrast(string $prva, string $druga): float
    {
        return (max($this->osvetljenost($prva), $this->osvetljenost($druga)) + 0.05) / (min($this->osvetljenost($prva), $this->osvetljenost($druga)) + 0.05);
    }
}
