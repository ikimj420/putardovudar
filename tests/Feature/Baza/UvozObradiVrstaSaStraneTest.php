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
use Tests\Podrska\LazniClaude;

// Paket 68/T5: kad model pogreši vrstu, a na strani je dokazana tačno jedna vrsta, uzima se ta; inače nacrt kao do sada.
#[Group('baza')]
class UvozObradiVrstaSaStraneTest extends BazaTestCase
{
    use RefreshDatabase;

    private const CLAUDE = LazniClaude::ADRESA;

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
        Http::fake([self::STRANA => Http::response('<html><body>'.$telo.'<p>'.self::GRUPA.'.</p></body></html>'), self::CLAUDE => LazniClaude::odgovor(json_encode($odgovor, JSON_UNESCAPED_UNICODE))]);

        $this->artisan('uvoz:obradi');

        return $nacrt->fresh();
    }

    #[Test]
    public function pogresna_vrsta_modela_se_menja_jedinom_vrstom_dokazanom_na_strani(): void
    {
        // Na strani nema reči „praksa", a ima „poziv" (primer: Erasmus+ mentorski program).
        $prilika = $this->obradi('<p>Poziv za podršku organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'praksa']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Konkurs, $prilika->vrsta);
        $this->assertSame('kod', $prilika->predlog['vrsta_izvor']);
        $this->assertSame('praksa', $prilika->predlog['vrsta_modela']);
        $this->assertSame('konkurs', $prilika->predlog['vrsta']);
    }

    // Ogledalo: dokazana vrsta modela se ne menja, ni kad je na strani dokazana još neka.
    #[Test]
    public function dokazana_vrsta_modela_se_ne_menja(): void
    {
        $prilika = $this->obradi('<p>Poziv i radionica. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'obuka']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Obuka, $prilika->vrsta);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }

    // Ogledalo: model je u pravu i jedina dokazana vrsta je njegova, pa vrstu ostavlja model.
    #[Test]
    public function vrsta_modela_koja_je_jedina_dokazana_ostaje_modelova(): void
    {
        $prilika = $this->obradi('<p>Poziv za podršku organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'konkurs']);

        $this->assertSame(VrstaPrilike::Konkurs, $prilika->vrsta);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
        $this->assertSame('konkurs', $prilika->predlog['vrsta_modela']);
    }

    /** @return array<string, array{0: string, 1: string}> telo strane, vrsta koju kaže model */
    public static function vrstaKojaSeNeMenja(): array
    {
        return [
            'dve vrste dokazane' => ['<p>Poziv i radionica. Rok za prijavu je 20. oktobra 2026. godine.</p>', 'stipendija'],
            'nijedna vrsta dokazana' => ['<p>Rok za prijavu je 20. oktobra 2026. godine.</p>', 'praksa'],
            'drugo bez ijedne dokazane' => ['<p>Rok za prijavu je 20. oktobra 2026. godine.</p>', 'drugo'],
        ];
    }

    // Više vrsta ili nijedna: ostaje vrsta modela, pa nacrt kao do sada.
    #[Test]
    #[DataProvider('vrstaKojaSeNeMenja')]
    public function kad_nije_dokazana_tacno_jedna_vrsta_ostaje_nacrt(string $telo, string $vrsta): void
    {
        $prilika = $this->obradi($telo, ['vrsta' => $vrsta]);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('vrsta nije potkrepljena', $prilika->predlog['odbijeno']);
        $this->assertSame($vrsta, $prilika->predlog['vrsta']);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }

    // Kod ne bira vrstu kad model kaže da nije prilika.
    #[Test]
    public function vrsta_se_ne_menja_kad_model_kaze_da_pravilo_ne_vazi(): void
    {
        $prilika = $this->obradi('<p>Poziv za podršku organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'praksa', 'vazi_pravilo' => false]);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('pravilo za objavu ne važi', $prilika->predlog['odbijeno']);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }

    // Paket 72/T2 (prepisuje tvrdnju iz paketa 68/T5, koja je kodu davala da od „praksa" napravi „posao"): kod ne dodeljuje „posao" umesto modela, jer posao
    // važi i bez grupe. Model kaže „praksa", na strani je dokazan samo posao: vrsta ostaje modelova i nije potkrepljena.
    #[Test]
    public function kod_ne_pravi_posao_od_druge_vrste(): void
    {
        $prilika = $this->obradi('<p>Oglas za posao radnika u skladištu. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'praksa', 'grupe' => []]);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('vrsta nije potkrepljena', $prilika->predlog['odbijeno']);
        $this->assertSame('praksa', $prilika->predlog['vrsta']);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }

    // Ogledalo: kad model sam kaže „posao" i to piše na strani, oglas se objavljuje bez grupe, kao i pre.
    #[Test]
    public function posao_koji_je_rekao_model_ne_traži_grupu(): void
    {
        $prilika = $this->obradi('<p>Oglas za posao radnika u skladištu. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'posao', 'grupe' => []]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Posao, $prilika->vrsta);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }

    // Primer #13 iz probe 71: vebinar „namenjen zaposlenima u ustanovama"; model kaže „obuka", a jedina reč vrste na strani je „zaposlenima".
    #[Test]
    public function vebinar_za_zaposlene_nije_posao(): void
    {
        $prilika = $this->obradi(
            '<p>Vebinar je namenjen zaposlenima u ustanovama koje sprovode mobilnosti učenika. Rok za prijavu je 20. oktobra 2026. godine.</p>',
            ['vrsta' => 'obuka', 'grupe' => [], 'citat' => 'Rok za prijavu je 20. oktobra 2026. godine'],
        );

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('vrsta nije potkrepljena', $prilika->predlog['odbijeno']);
        $this->assertSame('obuka', $prilika->predlog['vrsta']);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> vrsta koju kaže model, telo strane, vrsta posle obrade (ostale zamene iz 68/T5 rade kao do sada) */
    public static function zameneKojeSeDozvoljavaju(): array
    {
        return [
            'obuka postaje konkurs (mentorski program)' => ['obuka', '<p>Poziv za mentorski program podrške organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', 'konkurs'],
            'praksa postaje konkurs (mentorski program)' => ['praksa', '<p>Poziv za mentorski program podrške organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', 'konkurs'],
            'posao postaje konkurs' => ['posao', '<p>Poziv za podršku organizacijama. Rok za prijavu je 20. oktobra 2026. godine.</p>', 'konkurs'],
            'obuka postaje stipendija' => ['obuka', '<p>Stipendija za studente. Rok za prijavu je 20. oktobra 2026. godine.</p>', 'stipendija'],
        ];
    }

    #[Test]
    #[DataProvider('zameneKojeSeDozvoljavaju')]
    public function ostale_zamene_vrste_rade_kao_do_sada(string $vrstaModela, string $telo, string $ocekivana): void
    {
        $prilika = $this->obradi($telo, ['vrsta' => $vrstaModela]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame($ocekivana, $prilika->vrsta->value);
        $this->assertSame('kod', $prilika->predlog['vrsta_izvor']);
        $this->assertSame($vrstaModela, $prilika->predlog['vrsta_modela']);
    }

    // Paket 70/T6: „mera pripravništva za mlade sa srednjim obrazovanjem" je praksa (model kaže „praksa", a reč „praksa" ne piše na strani).
    #[Test]
    public function mera_pripravnistva_je_praksa(): void
    {
        $prilika = $this->obradi('<p>Javni poziv za realizaciju mere pripravništva za mlade sa srednjim obrazovanjem. Rok za prijavu je 20. oktobra 2026. godine.</p>', ['vrsta' => 'praksa']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Praksa, $prilika->vrsta);
        $this->assertSame('model', $prilika->predlog['vrsta_izvor']);
    }
}
