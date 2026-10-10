<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\PisanjeOdgovora;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 43: pomoćnik zna grupe. Grupa iz pitanja se poredi sa grupama prilike (paket 39), ne kao ključna reč.
#[Group('baza')]
class PomocnikGrupeTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 9, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(string $naslov, array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => $naslov, 'vrsta' => VrstaPrilike::Stipendija, 'mesto' => null, 'rok' => '2026-11-01',
            'kratak_opis' => 'Kratak opis.', 'opis' => 'Opis bez reči o grupi.',
        ], ...$dopuna]);
    }

    /** @param  array<string, mixed>  $forma */
    private function model(array $forma = []): void
    {
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => json_encode([...['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], ...$forma], JSON_UNESCAPED_UNICODE)]])]);
    }

    private function pitaj(string $pitanje): RezultatPretrage
    {
        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    /** @return list<string> */
    private function naslovi(RezultatPretrage $rezultat): array
    {
        return $rezultat->zapisi->pluck('naslov')->all();
    }

    /** @return array<string, array{0: Grupa, 1: string}> grupa, pitanje koje kaže njenu reč */
    public static function grupeUPitanju(): array
    {
        return [
            'osnovci' => [Grupa::Osnovci, 'Ja sam osnovac, šta ima za mene?'],
            'srednjoskolci' => [Grupa::Srednjoskolci, 'Ja sam srednjoškolac, šta ima za mene?'],
            'studenti' => [Grupa::Studenti, 'Ja sam student, šta ima za mene?'],
            'master-doktorski' => [Grupa::MasterDoktorski, 'Šta ima za doktorske studije?'],
            'nezaposleni-mladi' => [Grupa::NezaposleniMladi, 'Šta ima za nezaposlene mlade?'],
            'povratnici' => [Grupa::Povratnici, 'Šta ima za povratnike iz inostranstva?'],
            'organizacije' => [Grupa::Organizacije, 'Šta ima za udruženja građana?'],
            'romi' => [Grupa::Romi, 'Ja sam Romkinja, šta ima za mene?'],
        ];
    }

    #[Test]
    #[DataProvider('grupeUPitanju')]
    public function pitanje_sa_recju_grupe_vraca_samo_prilike_za_tu_grupu(Grupa $grupa, string $pitanje): void
    {
        $this->prilika('Za grupu', ['grupe' => [$grupa->value]]);
        $this->prilika('Za sve grupe', ['grupe' => array_map(fn (Grupa $g) => $g->value, Grupa::cases())]);
        // Ogledala: bez grupa, samo za druge grupe i sa rečju iz pitanja u tekstu (ali bez grupe) ne ulaze.
        $this->prilika('Bez grupa');
        $this->prilika('Samo druge grupe', ['grupe' => array_map(fn (Grupa $g) => $g->value, array_filter(Grupa::cases(), fn (Grupa $druga) => $druga !== $grupa))]);
        $this->prilika('Samo pominje', ['opis' => 'student osnovac srednjoškolac doktorske studije nezaposleni povratnici udruženja Romkinja']);
        $this->model();

        $rezultat = $this->pitaj($pitanje);

        $this->assertContains($grupa, $rezultat->formular->grupe);
        $this->assertEqualsCanonicalizing(['Za grupu', 'Za sve grupe'], $this->naslovi($rezultat), $grupa->value);
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> fraza koju vrati model, pitanje, reči koje ostaju */
    public static function frazeSaRecimaGrupe(): array
    {
        return [
            'ime sa rečju grupe' => ['Opre Roma', 'Gde je Opre Roma?', ['opre']],
            'predlog ispada' => ['studente u tehnici', 'Ima li nešto za studente u tehnici?', ['tehnici']],
            'sama grupa ispada' => ['studente', 'Ima li nešto za studente?', []],
            'bez reči grupe ostaje kakva jeste' => ['skladištu', 'Ima li nešto u skladištu?', ['skladištu']],
        ];
    }

    /** @param  list<string>  $ostaje */
    #[Test]
    #[DataProvider('frazeSaRecimaGrupe')]
    public function fraza_sa_recju_grupe_zadrzava_ostatak_a_sama_grupa_ispada(string $fraza, string $pitanje, array $ostaje): void
    {
        $this->model(['kljucne_reci' => [$fraza]]);

        $this->assertSame($ostaje, $this->pitaj($pitanje)->formular->kljucneReci);
    }

    // Grupu iz pitanja određuje kod po rečniku (paket 40), ne model: model koji ništa ne vrati ne gubi grupu.
    #[Test]
    public function grupu_odredjuje_kod_a_model_koji_nista_ne_vrati_je_ne_gubi(): void
    {
        $this->prilika('Za studente', ['grupe' => ['studenti']]);
        $this->model(['kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ja sam student, šta ima za mene?');

        $this->assertSame([Grupa::Studenti], $rezultat->formular->grupe);
        $this->assertSame(['Za studente'], $this->naslovi($rezultat));
        $this->assertFalse($rezultat->formular->jePrazan());
    }

    // Ogledalo: pitanje bez reči grupe nema grupe, pa prilike sa grupama i bez njih ulaze podjednako.
    #[Test]
    public function pitanje_bez_reci_grupe_nema_grupe_i_ne_sužava_pretragu(): void
    {
        $this->prilika('Za studente', ['grupe' => ['studenti']]);
        $this->prilika('Bez grupa');
        $this->model(['vrsta' => 'stipendija']);

        $rezultat = $this->pitaj('Ima li stipendija?');

        $this->assertSame([], $rezultat->formular->grupe);
        $this->assertEqualsCanonicalizing(['Za studente', 'Bez grupa'], $this->naslovi($rezultat));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function recGrupeUFormularu(): array
    {
        return [
            'u „kome" i među rečima' => [['kome' => 'studente', 'kljucne_reci' => ['studente']]],
            'samo među rečima' => [['kome' => null, 'kljucne_reci' => ['studente']]],
        ];
    }

    // Reči koje su ime grupe ne idu među reči koje prilika mora da ima u tekstu: model reč stavi u „kome" ili samo među
    // ključne reči, a prilika je za studente samo po grupi (naslov i opis nemaju reč „studente").
    /** @param  array<string, mixed>  $forma */
    #[Test]
    #[DataProvider('recGrupeUFormularu')]
    public function reci_koje_su_ime_grupe_ne_moraju_da_pisu_u_tekstu_prilike(array $forma): void
    {
        $this->prilika('Stipendija A', ['grupe' => ['studenti']]);
        $this->model(['vrsta' => 'stipendija', ...$forma]);

        $rezultat = $this->pitaj('Ima li stipendija za studente?');

        $this->assertSame([], $rezultat->formular->kljucneReci);
        $this->assertNull($rezultat->formular->kome);
        $this->assertSame(['Stipendija A'], $this->naslovi($rezultat));
    }

    // Ogledalo: druge reči iz pitanja i dalje sužavaju (grupa ne briše ostale uslove).
    #[Test]
    public function grupa_ne_brise_ostale_reci_iz_pitanja(): void
    {
        $this->prilika('Računarstvo', ['grupe' => ['studenti'], 'opis' => 'Stipendija za računarstvo.']);
        $this->prilika('Medicina', ['grupe' => ['studenti'], 'opis' => 'Stipendija za medicinu.']);
        $this->model(['vrsta' => 'stipendija', 'kljucne_reci' => ['računarstvo']]);

        $this->assertSame(['Računarstvo'], $this->naslovi($this->pitaj('Ima li stipendija za studente računarstva?')));
    }

    // Dve grupe u pitanju: prilika za bilo koju od njih ulazi, i samo takva.
    #[Test]
    public function dve_grupe_u_pitanju_traze_prilike_za_bar_jednu_od_njih(): void
    {
        $this->prilika('Za studente', ['grupe' => ['studenti']]);
        $this->prilika('Za Rome', ['grupe' => ['romi']]);
        $this->prilika('Za osnovce', ['grupe' => ['osnovci']]);
        $this->model();

        $rezultat = $this->pitaj('Ja sam student i Romkinja, šta ima za mene?');

        $this->assertSame([Grupa::Studenti, Grupa::Romi], $rezultat->formular->grupe);
        $this->assertEqualsCanonicalizing(['Za studente', 'Za Rome'], $this->naslovi($rezultat));
    }

    // Grupa zajedno sa vrstom i gradom: ostaje samo presek.
    #[Test]
    public function grupa_vrsta_i_grad_zajedno_daju_presek(): void
    {
        $this->prilika('Posao Niš studenti', ['vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'grupe' => ['studenti']]);
        $this->prilika('Posao Beograd studenti', ['vrsta' => VrstaPrilike::Posao, 'mesto' => 'Beograd', 'grupe' => ['studenti']]);
        $this->prilika('Praksa Niš studenti', ['vrsta' => VrstaPrilike::Praksa, 'mesto' => 'Niš', 'grupe' => ['studenti']]);
        $this->prilika('Posao Niš Romi', ['vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'grupe' => ['romi']]);
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš']);

        $this->assertSame(['Posao Niš studenti'], $this->naslovi($this->pitaj('Ja sam student, ima li posla u Nišu?')));
    }

    // Vrsta koju je model samo pogodio ne krije priliku, ali grupa ostaje tvrda i u rezervnoj pretrazi.
    #[Test]
    public function rezervna_pretraga_bez_vrste_cuva_grupu(): void
    {
        $this->prilika('Radionica za studente', ['vrsta' => VrstaPrilike::Drugo, 'grupe' => ['studenti']]);
        $this->prilika('Radionica za Rome', ['vrsta' => VrstaPrilike::Drugo, 'grupe' => ['romi']]);
        $this->model(['vrsta' => 'obuka']);

        $rezultat = $this->pitaj('Ja sam student, gde ima kursa?');

        $this->assertSame(['Radionica za studente'], $this->naslovi($rezultat));
    }

    // Samo javne prilike: nacrt, arhivirano i isteklo iste grupe ne ulaze.
    #[Test]
    public function nejavne_prilike_iste_grupe_ne_ulaze(): void
    {
        $this->prilika('Javna', ['grupe' => ['studenti']]);
        Prilika::factory()->create(['naslov' => 'Nacrt', 'status' => StatusPrilike::Nacrt, 'grupe' => ['studenti'], 'rok' => '2026-11-01']);
        Prilika::factory()->arhivirana()->create(['naslov' => 'Arhivirana', 'grupe' => ['studenti'], 'rok' => '2026-11-01']);
        $this->prilika('Istekla', ['grupe' => ['studenti'], 'rok' => '2026-10-01']);
        $this->model();

        $this->assertSame(['Javna'], $this->naslovi($this->pitaj('Ja sam student, šta ima za mene?')));
        $this->assertSame(4, Prilika::query()->zaGrupu(Grupa::Studenti)->count());
    }

    // Nema prilike za grupu: „nemam podatak", a model se ne zove drugi put.
    #[Test]
    public function grupa_bez_prilika_daje_nemam_podatak(): void
    {
        $this->prilika('Za studente', ['grupe' => ['studenti']]);
        $this->prilika('Bez grupa');
        $this->model();

        $rezultat = $this->pitaj('Ima li nešto za Rome?');

        $this->assertSame([Grupa::Romi], $rezultat->formular->grupe);
        $this->assertTrue($rezultat->nemaPodatak());
        $this->assertSame(1, Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA)->count());
    }

    #[Test]
    public function formular_samo_sa_grupom_nije_prazan(): void
    {
        $this->assertTrue((new Formular)->jePrazan());
        $this->assertFalse((new Formular(grupe: [Grupa::Studenti]))->jePrazan());
    }

    // Model dobija i grupe prilike, da odgovor može da ih pomene; ogledalo: prilika bez grupa ne piše red.
    #[Test]
    public function kontekst_za_model_pise_kome_je_prilika_namenjena(): void
    {
        $sa = $this->prilika('Sa grupama', ['grupe' => ['studenti', 'romi']]);
        $bez = $this->prilika('Bez grupa');

        $this->assertStringContainsString('Kome je namenjeno: Studenti, Romi i Romkinje', PisanjeOdgovora::kontekst(collect([$sa])));
        $this->assertStringNotContainsString('Kome je namenjeno', PisanjeOdgovora::kontekst(collect([$bez])));
    }

    // Ceo put: „Ja sam student, šta ima za mene?" vraća prilike za studente kao izvore i odgovor modela koji prođe proveru.
    #[Test]
    public function ja_sam_student_vraca_prilike_za_studente(): void
    {
        $this->prilika('Stipendija za studente', ['grupe' => ['studenti']]);
        $this->prilika('Stipendija za srednjoškolce', ['grupe' => ['srednjoskolci']]);
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => null, 'grad' => null, 'kome' => 'student', 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => 'Za studente postoji Stipendija za studente.']]);
        }]);

        $odgovor = app(Pomocnik::class)->odgovori('Ja sam student, šta ima za mene?');

        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertSame(['Stipendija za studente'], array_map(fn ($izvor) => $izvor->naslov, $odgovor->izvori));
        $this->assertFalse($odgovor->jeIzSablona());
    }
}
