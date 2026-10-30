<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\Prikaz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 81 (Ivan, 29.10.2026.): kalendar se sam puni nacrtima iz objavljenih prilika kojima je rok prošao (osim poslova); objavljuje Ivan, u adminu.
#[Group('baza')]
class KalendarIzPrilikaKomandaTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prosla(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => 'Konkurs za stipendije', 'vrsta' => VrstaPrilike::Konkurs, 'rok' => '2026-09-30',
            'naziv_izvora' => 'Fond za mlade talente', 'link_izvora' => 'https://primer.rs/konkurs',
        ], ...$dopuna]);
    }

    private function pokreni(): void
    {
        Artisan::call('kalendar:iz-prilika');
    }

    private function ispis(): string
    {
        return trim(Artisan::output());
    }

    #[Test]
    public function prilika_kojoj_je_rok_prosao_postaje_nacrt_u_kalendaru_popunjen_iz_prilike(): void
    {
        $prilika = $this->prosla(['grupe' => [Grupa::Mladi->value]]);

        $this->artisan('kalendar:iz-prilika')->expectsOutput('Novih nacrta u kalendaru: 1.')->assertExitCode(0);

        $stavka = KalendarStavka::query()->sole();
        $this->assertSame(StatusObjave::Nacrt, $stavka->status);
        $this->assertSame('Konkurs za stipendije', $stavka->naziv);
        $this->assertSame('Fond za mlade talente', $stavka->ko_daje);
        $this->assertSame('2026-09-30', $stavka->otvoreno_do->toDateString());
        $this->assertSame('https://primer.rs/konkurs', $stavka->link_izvora);
        $this->assertSame([Grupa::Mladi->value], $stavka->grupe);
        $this->assertSame(Prikaz::datum($prilika->rok), Prikaz::datum($stavka->otvoreno_do));
    }

    // Ogledalo: drugi prolaz ništa ne dodaje, a isti rok iste prilike ostaje jedna stavka; nov rok iste prilike bi bila nova stavka.
    #[Test]
    public function drugi_prolaz_ne_dodaje_nista(): void
    {
        $this->prosla();

        $this->pokreni();
        $this->assertSame('Novih nacrta u kalendaru: 1.', $this->ispis());

        $this->pokreni();
        $this->assertSame('Novih nacrta u kalendaru: 0.', $this->ispis());
        $this->assertSame(1, KalendarStavka::query()->count());
    }

    // Ako je Ivan stavku već dodao ručno (ili objavio), komanda je ne dira i ne dupla.
    #[Test]
    public function vec_postojeca_stavka_se_ne_dupla_ni_menja(): void
    {
        $this->prosla();
        $objavljena = KalendarStavka::factory()->objavljena()->create(['naziv' => 'Ivanov naziv', 'otvoreno_do' => '2026-09-30', 'link_izvora' => 'https://primer.rs/konkurs']);

        $this->pokreni();

        $this->assertSame('Novih nacrta u kalendaru: 0.', $this->ispis());
        $this->assertSame(1, KalendarStavka::query()->count());
        $this->assertSame(StatusObjave::Objavljeno, $objavljena->fresh()->status);
        $this->assertSame('Ivanov naziv', $objavljena->fresh()->naziv);
    }

    /** @return array<string, array{0: VrstaPrilike, 1: int}> vrsta => koliko nacrta nastaje */
    public static function vrste(): array
    {
        return [
            'posao ne' => [VrstaPrilike::Posao, 0],
            'stipendija da' => [VrstaPrilike::Stipendija, 1],
            'konkurs da' => [VrstaPrilike::Konkurs, 1],
            'obuka da' => [VrstaPrilike::Obuka, 1],
            'praksa da' => [VrstaPrilike::Praksa, 1],
            'drugo da' => [VrstaPrilike::Drugo, 1],
        ];
    }

    #[Test]
    #[DataProvider('vrste')]
    public function oglas_za_posao_ne_ide_u_kalendar_a_ostale_vrste_idu(VrstaPrilike $vrsta, int $ocekivano): void
    {
        $this->prosla(['vrsta' => $vrsta]);

        $this->pokreni();

        $this->assertSame(sprintf('Novih nacrta u kalendaru: %d.', $ocekivano), $this->ispis());
        $this->assertSame($ocekivano, KalendarStavka::query()->count());
    }

    // Samo objavljena je prošla pravilo objave: nacrt i arhivirana ne idu, a objavljena iste vrste i roka ide (ogledalo).
    #[Test]
    public function nacrt_i_arhivirana_prilika_ne_idu_a_objavljena_ide(): void
    {
        Prilika::factory()->create(['naslov' => 'Nacrt', 'vrsta' => VrstaPrilike::Konkurs, 'rok' => '2026-09-30']);
        Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana', 'vrsta' => VrstaPrilike::Konkurs, 'rok' => '2026-09-30']);

        $this->pokreni();
        $this->assertSame('Novih nacrta u kalendaru: 0.', $this->ispis());
        $this->assertSame(0, KalendarStavka::query()->count());

        $this->prosla(['naslov' => 'Objavljena']);
        $this->pokreni();
        $this->assertSame('Novih nacrta u kalendaru: 1.', $this->ispis());
        $this->assertSame(['Objavljena'], KalendarStavka::query()->pluck('naziv')->all());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int}> */
    public static function rokovi(): array
    {
        return [
            'juče je prošao' => [['rok' => '2026-10-04'], 1],
            'rok danas nije prošao' => [['rok' => '2026-10-05'], 0],
            'rok sutra nije prošao' => [['rok' => '2026-10-06'], 0],
            'bez roka' => [['rok' => null], 0],
            'stalno otvoreno sa starim rokom' => [['rok' => '2026-01-10', 'rok_stalno_otvoren' => true], 0],
            'pre godinu dana' => [['rok' => '2025-10-05'], 1],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('rokovi')]
    public function ulazi_samo_prilika_kojoj_je_rok_prosao(array $dopuna, int $ocekivano): void
    {
        $this->prosla($dopuna);

        $this->pokreni();

        $this->assertSame($ocekivano, KalendarStavka::query()->count(), json_encode($dopuna));
    }

    // Ništa se ne objavljuje samo: nacrt se ne vidi na javnoj strani kalendara, a posle Ivanove objave vidi (ogledalo).
    #[Test]
    public function komanda_nista_ne_objavljuje_a_posle_ivanove_objave_stavka_se_vidi(): void
    {
        $this->prosla();

        $this->pokreni();

        $this->assertSame(0, KalendarStavka::query()->where('status', StatusObjave::Objavljeno)->count());
        $this->assertSame(0, KalendarStavka::javne()->count());
        $this->get(route('kalendar.index'))->assertOk()->assertDontSee('Konkurs za stipendije');

        KalendarStavka::query()->sole()->update(['status' => StatusObjave::Objavljeno]);

        $this->assertSame(1, KalendarStavka::javne()->count());
        $this->get(route('kalendar.index'))->assertSee('Konkurs za stipendije');
    }

    // Mnogo prilika odjednom: svaka dobija svoju stavku, a ispis je tačan zbir.
    #[Test]
    public function ispis_je_broj_novih_nacrta(): void
    {
        foreach (range(1, 3) as $broj) {
            $this->prosla(['naslov' => 'Konkurs '.$broj, 'link_izvora' => 'https://primer.rs/konkurs-'.$broj]);
        }
        $this->prosla(['naslov' => 'Posao', 'vrsta' => VrstaPrilike::Posao, 'link_izvora' => 'https://primer.rs/posao']);

        $this->pokreni();

        $this->assertSame('Novih nacrta u kalendaru: 3.', $this->ispis());
        $this->assertSame(3, KalendarStavka::query()->count());
    }

    // Dnevni posao zove komandu posle obrade (i ona ništa ne traži sa interneta).
    #[Test]
    public function dnevni_posao_zove_kalendar_posle_obrade_i_stavka_nastaje(): void
    {
        Http::preventStrayRequests();
        config()->set('uvoz.izvori', []);
        config()->set('uvoz.spiskovi', []);
        $this->prosla();

        Artisan::call('uvoz:dnevno');
        $ispis = Artisan::output();

        $pozicije = array_map(fn (string $komanda) => strpos($ispis, '→ '.$komanda), ['uvoz:rss', 'uvoz:spiskovi', 'uvoz:jooble', 'uvoz:obradi', 'kalendar:iz-prilika']);
        $this->assertNotContains(false, $pozicije);
        $this->assertSame($pozicije, collect($pozicije)->sort()->values()->all(), 'redosled komandi');
        $this->assertStringContainsString('Novih nacrta u kalendaru: 1.', $ispis);
        $this->assertSame(1, KalendarStavka::query()->count());
    }
}
