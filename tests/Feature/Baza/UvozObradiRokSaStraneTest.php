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

    // Duga rečenica se seče oko datuma na cele reči i ostaje doslovan deo strane; reč roka je blizu datuma (paket 70), a ostatak rečenice je dug.
    #[Test]
    public function duga_recenica_sa_rokom_se_seče_oko_datuma(): void
    {
        $pre = implode(' ', array_map(fn (int $i) => 'pre'.$i, range(1, 40)));
        $posle = implode(' ', array_map(fn (int $i) => 'posle'.$i, range(1, 40)));
        $prilika = $this->obradi('<p>Poziv. '.$pre.' Rok je 20. oktobra 2026. godine '.$posle.'.</p>', ['citat' => 'Prijave traju do 20. oktobra 2026']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $citat = (string) $prilika->predlog['citat'];
        $this->assertLessThan(260, mb_strlen($citat));
        $this->assertStringContainsString('20. oktobra 2026.', $citat);
        // Najviše 12 reči pre datuma (uključujući „Rok je") i 14 od datuma (datum su četiri reči).
        $this->assertStringStartsWith('pre31 ', $citat);
        $this->assertStringEndsWith(' posle10', $citat);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> rok koji model vrati, citat modela, da li prolazi */
    public static function dvaDatumaURecenici(): array
    {
        return [
            'datum uz koji stoji rok' => ['2026-10-11', 'Prijave traju do 11. oktobra 2026', true],
            'datum uz događaj' => ['2026-10-17', 'Radionica se održava 17. oktobra 2026', false],
        ];
    }

    // Paket 70/T5: „Rok za prijavu je 11. oktobra, a radionica je 17. oktobra." dokazuje samo 11. oktobar.
    #[Test]
    #[DataProvider('dvaDatumaURecenici')]
    public function recenica_sa_dva_datuma_dokazuje_samo_onaj_uz_koji_stoji_rok(string $rok, string $citat, bool $prolazi): void
    {
        $prilika = $this->obradi('<p>Poziv organizacijama. Rok za prijavu je 11. oktobra 2026. godine, a radionica je 17. oktobra 2026. godine.</p>', ['rok' => $rok, 'citat' => $citat]);

        $this->assertSame($prolazi ? StatusPrilike::Objavljeno : StatusPrilike::Nacrt, $prilika->status);

        if ($prolazi) {
            $this->assertSame($rok, $prilika->rok->toDateString());
            $this->assertStringContainsString('Rok za prijavu je 11. oktobra 2026.', (string) $prilika->predlog['citat']);
        } else {
            $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
        }
    }

    /** @return array<string, array{0: string}> strana na kojoj piše drugi datum nego što model kaže */
    public static function drugiDatum(): array
    {
        return [
            'druga godina' => ['<p>Poziv organizacijama. Rok za prijavu je 20. oktobra 2027. godine.</p>'],
            'drugi mesec' => ['<p>Poziv organizacijama. Rok za prijavu je 20. novembra 2026. godine.</p>'],
            'drugi dan' => ['<p>Poziv organizacijama. Rok za prijavu je 21. oktobra 2026. godine.</p>'],
        ];
    }

    // Rečenica sa rokom mora da ima isti datum: ista godina, mesec i dan.
    #[Test]
    #[DataProvider('drugiDatum')]
    public function recenica_sa_rokom_za_drugi_datum_se_ne_uzima(string $telo): void
    {
        $prilika = $this->obradi($telo, ['rok' => '2026-10-20', 'citat' => 'Prijave traju do 20. oktobra 2026']);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
    }

    /** @return array<string, array{0: string}> prva rečenica sa „Rok" je za drugi datum, tek druga za pravi */
    public static function prvaRecenicaJeZaDrugiDatum(): array
    {
        return [
            'druga godina' => ['Rok za prijavu radionice je 20. oktobra 2027. godine. Rok za prijavu konkursa je 20. oktobra 2026. godine.'],
            'drugi mesec' => ['Rok za prijavu radionice je 20. novembra 2026. godine. Rok za prijavu konkursa je 20. oktobra 2026. godine.'],
            'drugi dan' => ['Rok za prijavu radionice je 21. oktobra 2026. godine. Rok za prijavu konkursa je 20. oktobra 2026. godine.'],
        ];
    }

    // Među više rečenica sa rokom bira se ona sa pravim datumom (ne prva), pa nacrt prolazi umesto da ga kasnija provera odbije.
    #[Test]
    #[DataProvider('prvaRecenicaJeZaDrugiDatum')]
    public function medju_vise_recenica_sa_rokom_bira_se_ona_sa_pravim_datumom(string $recenice): void
    {
        $prilika = $this->obradi('<p>Poziv organizacijama. '.$recenice.'</p>', ['rok' => '2026-10-20', 'citat' => 'Prijave traju do 20. oktobra 2026']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('Rok za prijavu konkursa je 20. oktobra 2026. godine.', $prilika->predlog['citat']);
    }

    /** @return array<string, array{0: int, 1: bool}> reči između „Rok" i datuma, da li prolazi */
    public static function razmakIzmedjuRokaIDatuma(): array
    {
        return [
            'odmah' => [0, true],
            'deset reči' => [10, true],
            'poslednja koja staje (dvanaest ukupno)' => [11, true],
            'prva koja ne staje (trinaest)' => [12, false],
            'daleko' => [30, false],
        ];
    }

    #[Test]
    #[DataProvider('razmakIzmedjuRokaIDatuma')]
    public function reč_roka_mora_da_stoji_najviše_dvanaest_reči_ispred_datuma(int $reci, bool $prolazi): void
    {
        $punjenje = $reci === 0 ? '' : implode(' ', array_map(fn (int $i) => 'rec'.$i, range(1, $reci))).' ';
        $prilika = $this->obradi('<p>Poziv organizacijama. Rok '.$punjenje.'20. oktobra 2026. godine.</p>', ['citat' => 'Prijave traju do 20. oktobra 2026']);

        $this->assertSame($prolazi ? StatusPrilike::Objavljeno : StatusPrilike::Nacrt, $prilika->status);
    }

    // Opseg „od … do …": reč uz prvi datum važi i za drugi (rok je kraj opsega).
    #[Test]
    public function opseg_od_do_ne_prekida_prozor(): void
    {
        $prilika = $this->obradi('<p>Poziv studentima. Prijave su otvorene od 28. septembra 2026. do 12. oktobra 2026. godine.</p>', ['rok' => '2026-10-12', 'citat' => 'Prijave traju do 12. oktobra 2026']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-10-12', $prilika->rok->toDateString());
    }

    private const OGLAS_62 = 'Ministarstvo oglašava Javni konkurs za popunjavanje izvršilačih radnih mesta. Rok za ponošenje prijave je 15 (petnaest) dana i počinje da teče od 1. oktobra zaključno sa 15. oktobrom 2026. godine.';

    // Paket 72/T1: oglas iz probe 71 (#62) piše rok mesecom u instrumentalu; model ga vrati ispravno, a kod ga je odbijao kao „rok ne piše u tekstu strane".
    #[Test]
    public function rok_sa_mesecom_u_instrumentalu_prolazi(): void
    {
        $prilika = $this->obradi('<p>'.self::OGLAS_62.'</p>', ['vrsta' => 'posao', 'grupe' => [], 'rok' => '2026-10-15', 'citat' => self::OGLAS_62]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Posao, $prilika->vrsta);
        $this->assertSame('2026-10-15', $prilika->rok->toDateString());
        $this->assertSame('model', $prilika->predlog['izvor_citata']);
    }

    // Ogledalo: rok koji model vrati za dan kog nema na strani i dalje ne prolazi.
    #[Test]
    public function drugi_dan_od_onog_u_instrumentalu_ne_prolazi(): void
    {
        $prilika = $this->obradi('<p>'.self::OGLAS_62.'</p>', ['vrsta' => 'posao', 'grupe' => [], 'rok' => '2026-10-16', 'citat' => self::OGLAS_62]);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
    }
}
