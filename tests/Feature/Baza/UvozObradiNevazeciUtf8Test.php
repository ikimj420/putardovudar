<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Model\ModelObrade;
use App\Services\Model\Potrosnja;
use App\Services\Ollama\OllamaNedostupna;
use App\Services\TekstStrane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\BazaTestCase;

// Paket 74 (dopuna, Ivan, 13.10.2026.): obrada ne sme da padne na nevažećem UTF-8. Odgovor modela sa njim je „odgovor modela nije ispravan", stavka ostaje neobrađena,
// a obrada ide dalje. Pravi uzrok pada u probi bio je trim po bajtovima u TekstStrane::normalizuj (citat „⏰ …" je postajao nevažeći), pa i to ima svoj test.
#[Group('baza')]
class UvozObradiNevazeciUtf8Test extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const ANTHROPIC = 'https://api.anthropic.com/v1/messages';

    private const GRUPA = 'Otvoreno je za sve studente';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
        config()->set('claude.kljuc', 'probni-kljuc-nije-pravi-123');
    }

    private function nacrt(string $naslov, string $link, ?string $izvorObjavljen = null): Prilika
    {
        return Prilika::factory()->create(['naslov' => $naslov, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => $link, 'izvor_objavljen' => $izvorObjavljen]);
    }

    /** @return array<string, mixed> */
    private function forma(string $citatRoka = 'Rok za prijavu je 20. oktobra 2026. godine'): array
    {
        return [
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za studente.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => $citatRoka,
            'grupe' => [['grupa' => 'studenti', 'citat' => self::GRUPA]],
        ];
    }

    private function strana(string $recenicaSaRokom = 'Rok za prijavu je 20. oktobra 2026. godine.'): mixed
    {
        return Http::response('<html><body><h1>Konkurs</h1><p>'.$recenicaSaRokom.'</p><p>'.self::GRUPA.'.</p></body></html>');
    }

    /** @return array<string, array{0: string}> MODEL_OBRADE */
    public static function modeli(): array
    {
        return ['Ollama' => ['ollama'], 'Claude' => ['claude']];
    }

    private function snimak(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/stranice/youth-atlas-neizgovorenih-emocija.html'));
    }

    /** @return array<string, mixed> pravi odgovor Claude-a za tu stranu (snimljen 13.10.2026.) */
    private function snimljenOdgovor(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/stranice/youth-atlas-neizgovorenih-emocija.odgovor-modela.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private const SNIMLJENA_STRANA = 'https://youth.rs/archives/18211';

    // (1) Odakle dolaze loši bajtovi: stavka koja je srušila prvu probu (Youth.rs, „Atlas neizgovorenih emocija"). Strana je UTF-8 (zaglavlje, meta i sadržaj), odgovor modela je ispravan
    // UTF-8, a citat koji počinje sa ⏰ (bajtovi E2 8F B0) postaje nevažeći tek kad se sa njega „navodnici" skidaju po bajtovima: trim() sa maskom koja ima bajt E2 odseče prvi bajt znaka.
    #[Test]
    public function snimak_pale_strane_i_odgovor_modela_su_ispravan_utf8_a_loši_bajtovi_nastaju_u_trim_u(): void
    {
        $strana = $this->snimak();
        $odgovor = $this->snimljenOdgovor();
        $citat = (string) $odgovor['citat'];

        // Strana: kodna strana je UTF-8 i sadržaj je važeći; nije kodna strana, pa nema šta da se pretvara.
        $this->assertStringContainsString('<meta charset="UTF-8"', $strana);
        $this->assertTrue(mb_check_encoding($strana, 'UTF-8'));
        $this->assertTrue(mb_check_encoding(TekstStrane::izHtmla($strana), 'UTF-8'));
        $this->assertStringContainsString('⏰ Prijave do 30. jula', TekstStrane::izHtmla($strana));

        // Odgovor modela: ispravan UTF-8, a citat počinje bajtovima E2 8F B0.
        array_walk_recursive($odgovor, fn (mixed $vrednost) => $this->assertTrue(! is_string($vrednost) || mb_check_encoding($vrednost, 'UTF-8')));
        $this->assertSame('e28fb0', bin2hex(substr($citat, 0, 3)));

        // Trim po bajtovima (kako je bilo): prvi bajt znaka je odsečen, ostaje 8F B0, što nije UTF-8. Ponašanje PHP-a, ne našeg koda.
        $stari = trim(mb_strtolower($citat), " \t\n\r\"'„“”‘’");
        $this->assertSame('8fb0', bin2hex(substr($stari, 0, 2)));
        $this->assertFalse(mb_check_encoding($stari, 'UTF-8'));

        // Isto sada, po znakovima: citat ostaje ceo i važeći.
        $this->assertSame('⏰ prijave do 30. jula.', TekstStrane::normalizuj($citat));
    }

    // (2) Popravka na izvoru: ista stranica i isti odgovor modela, obrada se završava, citat je na strani, a rok je prošao (jul 2026), pa ostaje nacrt sa tim razlogom.
    #[Test]
    #[DataProvider('modeli')]
    public function stavka_koja_je_pala_se_sada_obradjuje_do_kraja(string $obrada): void
    {
        config()->set('claude.model_obrade', $obrada);
        // Strana piše „Prijave do 30. jula" bez godine, pa godinu daje dan objave iz izvora (RSS), kao u probi: 20.7.2026.
        $nacrt = $this->nacrt('JAVNI POZIV: Atlas neizgovorenih emocija', self::SNIMLJENA_STRANA, '2026-07-20');
        $sadrzaj = json_encode($this->snimljenOdgovor(), JSON_UNESCAPED_UNICODE);
        Http::fake([
            self::SNIMLJENA_STRANA => Http::response($this->snimak(), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            self::OLLAMA => Http::response(['message' => ['content' => $sadrzaj]]),
            self::ANTHROPIC => Http::response(['content' => [['type' => 'text', 'text' => $sadrzaj]], 'usage' => ['input_tokens' => 2901, 'output_tokens' => 215]]),
        ]);

        $this->artisan('uvoz:obradi')
            ->expectsOutputToContain('ostaje nacrt (rok je prošao): JAVNI POZIV: Atlas neizgovorenih emocija')
            ->assertExitCode(0);

        $posle = $nacrt->fresh();
        $this->assertNotNull($posle->obradeno_at);
        $this->assertSame(StatusPrilike::Nacrt, $posle->status);
        $this->assertSame('rok je prošao', $posle->predlog['odbijeno']);
        $this->assertSame('2026-07-30', $posle->predlog['rok']);
        // Citat modela je na strani, pa ostaje njegov (ne zamenjuje ga rečenica koju traži kod), i ceo je važeći UTF-8.
        $this->assertSame('model', $posle->predlog['izvor_citata']);
        $this->assertSame('⏰ Prijave do 30. jula .', $posle->predlog['citat']);
        $this->assertTrue(mb_check_encoding((string) json_encode($posle->predlog, JSON_UNESCAPED_UNICODE), 'UTF-8'));
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> kako se u odgovor ubacuje nevažeći UTF-8 (bajtovi odsečeni usred znaka) */
    public static function nevazeciOdgovori(): array
    {
        $slomljen = "Rok \xE2\x8F";

        return [
            'u citatu roka' => [fn (array $forma) => [...$forma, 'citat' => $slomljen]],
            'u razlogu' => [fn (array $forma) => [...$forma, 'razlog' => $slomljen]],
            'u citatu grupe' => [fn (array $forma) => [...$forma, 'grupe' => [['grupa' => 'studenti', 'citat' => $slomljen]]]],
            'u vrsti' => [fn (array $forma) => [...$forma, 'vrsta' => $slomljen]],
        ];
    }

    /** Model koji ne proverava šta vraća: za nacrt sa „Loš odgovor" u naslovu daje $zaLosu (ili baca izuzetak), za sve ostale ispravan odgovor. */
    private function modelSaLosomStavkom(callable $zaLosu): void
    {
        $this->app->instance(ModelObrade::class, new class($zaLosu, $this->forma()) implements ModelObrade
        {
            /** @param  array<string, mixed>  $ispravan */
            public function __construct(private readonly mixed $zaLosu, private readonly array $ispravan) {}

            public function model(): string
            {
                return 'probni-model';
            }

            public function najviseZnakova(): int
            {
                return 12000;
            }

            public function odgovoriJsonom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): array
            {
                return str_contains($tekst, 'Naslov: Loš odgovor') ? ($this->zaLosu)() : $this->ispravan;
            }

            public function odgovoriTekstom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): string
            {
                return '';
            }

            public function potrosnja(): Potrosnja
            {
                return new Potrosnja('probni-model', 2, 0, 0, null);
            }
        });
    }

    private function dveStavke(): array
    {
        $losa = $this->nacrt('Loš odgovor', 'https://a.test/losa');
        $dobra = $this->nacrt('Dobar odgovor', 'https://a.test/dobra');
        Http::fake(['https://a.test/losa' => $this->strana(), 'https://a.test/dobra' => $this->strana()]);

        return [$losa, $dobra];
    }

    // (3) Zaštita: odgovor sa nevažećim UTF-8 je „odgovor modela nije ispravan"; stavka ostaje neobrađena, druga se obrađuje.
    /** @param  callable(array<string, mixed>): array<string, mixed>  $pokvari */
    #[Test]
    #[DataProvider('nevazeciOdgovori')]
    public function odgovor_sa_nevazecim_utf8_ostaje_neobradjen_a_obrada_ide_dalje(callable $pokvari): void
    {
        [$losa, $dobra] = $this->dveStavke();
        $this->modelSaLosomStavkom(fn () => $pokvari($this->forma()));

        $this->artisan('uvoz:obradi')
            ->expectsOutput('nije obrađen (odgovor modela nije ispravan): Loš odgovor')
            ->expectsOutputToContain('objavljen')
            ->expectsOutput('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 1.')
            ->assertExitCode(0);

        $this->assertNull($losa->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Nacrt, $losa->fresh()->status);
        $this->assertNotNull($dobra->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Objavljeno, $dobra->fresh()->status);
    }

    // (3) Šta god da u jednoj stavci pukne (ovde izuzetak iz obrade), ona ostaje neobrađena sa razlogom, a ostale se obrađuju.
    #[Test]
    public function izuzetak_u_jednoj_stavci_ne_zaustavlja_obradu(): void
    {
        [$losa, $dobra] = $this->dveStavke();
        $this->modelSaLosomStavkom(fn () => throw new RuntimeException("preg_match(): Compilation failed:\nUTF-8 error"));

        $this->artisan('uvoz:obradi')
            ->expectsOutput('nije obrađen (greška pri obradi: preg_match(): Compilation failed: UTF-8 error): Loš odgovor')
            ->expectsOutputToContain('objavljen')
            ->expectsOutput('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 1.')
            ->assertExitCode(0);

        $this->assertNull($losa->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Objavljeno, $dobra->fresh()->status);
    }

    // Ogledalo: nedostupan model i dalje zaustavlja obradu (nema smisla zvati ga za ostale), a ništa se ne menja.
    #[Test]
    public function nedostupan_model_i_dalje_zaustavlja_obradu(): void
    {
        [$losa, $dobra] = $this->dveStavke();
        $this->modelSaLosomStavkom(fn () => throw new OllamaNedostupna('Model ne odgovara'));

        $this->artisan('uvoz:obradi')->expectsOutputToContain('Model ne odgovara')->assertExitCode(1);

        $this->assertNull($losa->fresh()->obradeno_at);
    }

    // (3) Strana u drugoj kodnoj strani (windows-1250) se ne pretvara u prazan tekst: razlog piše, a ostale stavke idu dalje. Snimak je ista strana pretvorena u windows-1250;
    // izvori iz podešavanja su svi UTF-8 (80 snimljenih strana u probi 71 i strana ove stavke), pa pretvaranje po kodnoj strani nije uvedeno.
    #[Test]
    public function strana_koja_nije_u_utf8_ostaje_neobradjena_sa_razlogom(): void
    {
        $losa = $this->nacrt('Loš odgovor', 'https://a.test/losa');
        $dobra = $this->nacrt('Dobar odgovor', 'https://a.test/dobra');
        // Znaci koje windows-1250 nema (emodži, strelice) ispadaju pre pretvaranja; ostaju slova sa dijakriticima.
        $zaPretvaranje = (string) preg_replace('/[^\x{0000}-\x{017F}\x{2010}-\x{2026}]/u', '', str_replace('<meta charset="UTF-8"', '<meta charset="windows-1250"', $this->snimak()));
        $windows1250 = (string) iconv('UTF-8', 'CP1250', $zaPretvaranje);
        $this->assertFalse(mb_check_encoding($windows1250, 'UTF-8'));
        Http::fake(['https://a.test/losa' => Http::response($windows1250, 200, ['Content-Type' => 'text/html; charset=windows-1250']), 'https://a.test/dobra' => $this->strana()]);
        $this->modelSaLosomStavkom(fn () => $this->forma());

        $this->artisan('uvoz:obradi')
            ->expectsOutput('nije obrađen (strana nije u UTF-8): Loš odgovor')
            ->expectsOutput('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 1.')
            ->assertExitCode(0);

        $this->assertNull($losa->fresh()->obradeno_at);
        $this->assertSame(StatusPrilike::Objavljeno, $dobra->fresh()->status);
    }

    // (2) Sečenje po slovima: dugačka rečenica sa rokom se seče oko datuma; pre i posle datuma stoje znaci sa više bajtova (emodži, „İ" čije malo slovo ima drugačiju dužinu u bajtovima,
    // ćirilica pretvorena u latinicu). Citat mora da ostane ceo, važeći UTF-8 i doslovno na strani.
    #[Test]
    public function secenje_recenice_oko_roka_ne_cepa_znakove(): void
    {
        $pre = implode(' ', array_map(fn (int $i) => ($i % 7 === 0 ? '⏰' : ($i % 5 === 0 ? 'İstanbul' : 'čšžćđ'.$i)), range(1, 40)));
        $posle = implode(' ', array_map(fn (int $i) => ($i % 6 === 0 ? '💸' : 'Ђурђевдан'.$i), range(1, 40)));
        $nacrt = $this->nacrt('Prijava za program', 'https://a.test/prijava');
        $recenica = $pre.' Rok je 20. oktobra 2026. godine '.$posle.'.';
        $sadrzaj = json_encode($this->forma('Prijave traju do 20. oktobra 2026'), JSON_UNESCAPED_UNICODE);
        Http::fake(['https://a.test/prijava' => $this->strana($recenica), self::OLLAMA => Http::response(['message' => ['content' => $sadrzaj]])]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        $posle = $nacrt->fresh();
        $citat = (string) $posle->predlog['citat'];
        $this->assertSame(StatusPrilike::Objavljeno, $posle->status);
        $this->assertSame('kod', $posle->predlog['izvor_citata']);
        $this->assertTrue(mb_check_encoding($citat, 'UTF-8'));
        $this->assertStringContainsString('Rok je 20. oktobra 2026.', $citat);
        $this->assertStringContainsString('⏰', $citat);
        $this->assertLessThan(320, mb_strlen($citat));
    }
}
