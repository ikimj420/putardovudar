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

// Paket 68/T6: kad citat roka koji je vratio model nije doslovan na strani, kod traži rečenicu sa istim datumom i rečju roka; datum samo uz događaj ne prolazi.
#[Group('baza')]
class UvozObradiRokSaStraneTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const STRANA = 'https://a.test/oglas-1';

    private const GRUPA = 'Otvoreno je za sve studente';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
    }

    private function nacrt(): Prilika
    {
        return Prilika::factory()->create(['naslov' => 'Poziv za organizacije', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => self::STRANA]);
    }

    /** @param  array<string, mixed>  $forma */
    private function obradi(string $telo, array $forma): Prilika
    {
        $nacrt = $this->nacrt();
        $odgovor = [...[
            'vazi_pravilo' => true, 'razlog' => 'Poziv za studente.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine',
            'grupe' => [['grupa' => 'studenti', 'citat' => self::GRUPA]],
        ], ...$forma];
        Http::fake([self::STRANA => Http::response('<html><body>'.$telo.'<p>'.self::GRUPA.'.</p></body></html>'), self::OLLAMA => Http::response(['message' => ['content' => json_encode($odgovor, JSON_UNESCAPED_UNICODE)]])]);

        $this->artisan('uvoz:obradi');

        return $nacrt->fresh();
    }

    // Primer GLAS(NO) O PRAVIMA: na strani „predloga projekata", model napisao „projekta", pa citat nije doslovan, a rečenica sa rokom postoji.
    #[Test]
    public function citat_koji_nije_doslovan_menja_kod_recenicom_sa_rokom(): void
    {
        $prilika = $this->obradi(
            '<p>Poziv organizacijama. Rok za podnošenje predloga projekata je 20. oktobra 2026. godine. Pitanja šaljite mejlom.</p>',
            ['citat' => 'Rok za podnošenje predloga projekta je 20. oktobra 2026. godine'],
        );

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-10-20', $prilika->rok->toDateString());
        $this->assertSame('Rok za podnošenje predloga projekata je 20. oktobra 2026. godine.', $prilika->predlog['citat']);
        $this->assertSame('kod', $prilika->predlog['izvor_citata']);
    }

    // Ogledalo: doslovan citat se ne dira.
    #[Test]
    public function doslovan_citat_modela_ostaje_njegov(): void
    {
        $prilika = $this->obradi('<p>Poziv organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', []);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('Rok za prijavu je 20. oktobra 2026. godine', $prilika->predlog['citat']);
        $this->assertSame('model', $prilika->predlog['izvor_citata']);
    }

    /** @return array<string, array{0: string}> strana na kojoj datum piše samo uz događaj */
    public static function datumSamoUzDogadjaj(): array
    {
        return [
            'radionica će se održati' => ['<p>Poziv organizacijama. Radionica će se održati 20. oktobra 2026. godine.</p>'],
            'od do traje kurs' => ['<p>Poziv organizacijama. Od 15. do 20. oktobra 2026. godine traje kurs.</p>'],
            'bez reči roka' => ['<p>Poziv organizacijama. Događaj je 20. oktobra 2026. godine u Nišu.</p>'],
        ];
    }

    #[Test]
    #[DataProvider('datumSamoUzDogadjaj')]
    public function datum_samo_uz_dogadjaj_se_ne_uzima_kao_rok(string $telo): void
    {
        $prilika = $this->obradi($telo, ['citat' => 'Rok za prijavu je 20. oktobra 2026. godine']);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
        $this->assertSame('model', $prilika->predlog['izvor_citata']);
    }

    // Isti datum na dva mesta, jedno uz „rok": uzima se to.
    #[Test]
    public function isti_datum_na_dva_mesta_uzima_recenicu_sa_rokom(): void
    {
        $prilika = $this->obradi(
            '<p>Poziv organizacijama. Radionica je 20. oktobra 2026. godine u Nišu. Rok za prijavu je 20. oktobra 2026. godine.</p>',
            ['citat' => 'Prijave traju do 20. oktobra 2026. godine'],
        );

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('Rok za prijavu je 20. oktobra 2026. godine.', $prilika->predlog['citat']);
    }

    /** @return array<string, array{0: string, 1: string}> rečenica sa strane, očekivani citat */
    public static function recenicaBezReciRoka(): array
    {
        return [
            'prijave su otvorene do' => ['Prijave su otvorene do 20. oktobra 2026. godine.', 'Prijave su otvorene do 20. oktobra 2026. godine.'],
            'konkurs otvoren do' => ['Konkurs je otvoren do 20. oktobra 2026. godine.', 'Konkurs je otvoren do 20. oktobra 2026. godine.'],
            'poziv traje do' => ['Poziv traje do 20. oktobra 2026. godine.', 'Poziv traje do 20. oktobra 2026. godine.'],
            'prijave bez do' => ['Prijave se podnose 20. oktobra 2026. godine.', 'Prijave se podnose 20. oktobra 2026. godine.'],
        ];
    }

    #[Test]
    #[DataProvider('recenicaBezReciRoka')]
    public function do_uz_prijave_otvoreno_konkurs_ili_poziv_je_dovoljno(string $recenica, string $citat): void
    {
        $prilika = $this->obradi('<p>Poziv organizacijama. '.$recenica.' Hvala.</p>', ['citat' => 'Prijave traju do 20. oktobra 2026']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame($citat, $prilika->predlog['citat']);
    }

    // Samo „do" bez prijava, otvorenog, konkursa ili poziva nije rok.
    #[Test]
    public function samo_do_bez_reci_o_prijavi_nije_rok(): void
    {
        $prilika = $this->obradi('<p>Poziv organizacijama. Izložba traje do 20. oktobra 2026. godine. Hvala.</p>', ['citat' => 'Prijave traju do 20. oktobra 2026']);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
    }

    // Dan i mesec bez godine: rečenica sa rokom važi kao i do sada, godina je prva posle datuma objave.
    #[Test]
    public function dan_i_mesec_bez_godine_u_recenici_sa_rokom_prihvata_se_uz_datum_objave(): void
    {
        $nacrt = $this->nacrt();
        $nacrt->forceFill(['izvor_objavljen' => '2026-09-28'])->save();
        Http::fake([self::STRANA => Http::response('<html><body><p>Poziv studentima. Prijave su otvorene od 28. septembra do 12. oktobra na sajtu programa.</p><p>'.self::GRUPA.'.</p></body></html>'), self::OLLAMA => Http::response(['message' => ['content' => json_encode([
            'vazi_pravilo' => true, 'razlog' => 'Poziv.', 'vrsta' => 'konkurs', 'rok' => '2026-10-12', 'citat' => 'Prijave su otvorene od 28. septembra do 12. oktobra na sajtu s2s.fonis.rs',
            'grupe' => [['grupa' => 'studenti', 'citat' => self::GRUPA]],
        ], JSON_UNESCAPED_UNICODE)]])]);

        $this->artisan('uvoz:obradi');

        $prilika = $nacrt->fresh();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-10-12', $prilika->rok->toDateString());
        $this->assertSame('Prijave su otvorene od 28. septembra do 12. oktobra na sajtu programa.', $prilika->predlog['citat']);
        $this->assertSame('kod', $prilika->predlog['izvor_citata']);
    }

    // Bez citata objave nema, kao do sada: kod ne traži rečenicu umesto citata koga model nije naveo.
    #[Test]
    public function bez_citata_kod_ne_trazi_recenicu(): void
    {
        $prilika = $this->obradi('<p>Poziv organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['citat' => '']);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('model', $prilika->predlog['izvor_citata']);
    }

    // Duga rečenica se seče oko datuma na cele reči i ostaje doslovan deo strane.
    #[Test]
    public function duga_recenica_sa_rokom_se_seče_oko_datuma(): void
    {
        $pre = implode(' ', array_map(fn (int $i) => 'pre'.$i, range(1, 40)));
        $posle = implode(' ', array_map(fn (int $i) => 'posle'.$i, range(1, 40)));
        $prilika = $this->obradi('<p>Poziv. Rok za prijavu je '.$pre.' 20. oktobra 2026. godine '.$posle.'.</p>', ['citat' => 'Rok je 20. oktobra 2026. godine']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $citat = (string) $prilika->predlog['citat'];
        $this->assertLessThan(260, mb_strlen($citat));
        $this->assertStringContainsString('20. oktobra 2026.', $citat);
        // Najviše 12 reči pre datuma i 14 od datuma (datum su četiri reči).
        $this->assertStringStartsWith('pre29 ', $citat);
        $this->assertStringEndsWith(' posle10', $citat);
    }
}
