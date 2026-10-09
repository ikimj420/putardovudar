<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Ollama\OllamaNedostupna;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class PomocnikPretragaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        // Ni jedan zahtev ne ide na pravu Ollamu: što nije lažirano, baca izuzetak.
        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 9, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-01',
            'kratak_opis' => 'Puno radno vreme.', 'opis' => 'Rad u skladištu, smene.',
        ], ...$dopuna]);
    }

    /** @param  array<string, mixed>  $forma */
    private function model(array $forma): void
    {
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => json_encode($forma, JSON_UNESCAPED_UNICODE)]])]);
    }

    private function pozivaModela(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA)->count();
    }

    private function pitaj(string $pitanje): RezultatPretrage
    {
        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    #[Test]
    public function pitanje_sa_odgovorom_nalazi_samo_priliku_koja_odgovara(): void
    {
        $nis = $this->prilika();
        $this->prilika(['naslov' => 'Radnik u magacinu', 'mesto' => 'Beograd']);
        $this->prilika(['naslov' => 'Praksa u skladištu', 'vrsta' => VrstaPrilike::Praksa]);
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ima li posla u Nišu?');

        $this->assertSame([$nis->id], $rezultat->zapisi->pluck('id')->all());
        $this->assertSame(VrstaPrilike::Posao, $rezultat->formular->vrsta);
        $this->assertSame('Niš', $rezultat->formular->grad);
        $this->assertFalse($rezultat->nemaPodatak());
    }

    // Ogledalo: isto pitanje za drugi grad nalazi drugu priliku.
    #[Test]
    public function pitanje_za_drugi_grad_nalazi_drugu_priliku(): void
    {
        $this->prilika();
        $beograd = $this->prilika(['naslov' => 'Radnik u magacinu', 'mesto' => 'Beograd']);
        $this->model(['vrsta' => 'posao', 'grad' => 'Beograd', 'kome' => null, 'kljucne_reci' => []]);

        $this->assertSame([$beograd->id], $this->pitaj('Ima li posla u Beogradu?')->zapisi->pluck('id')->all());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function nejavne(): array
    {
        return [
            'nacrt' => [['status' => StatusPrilike::Nacrt]],
            'arhivirano' => [['status' => StatusPrilike::Arhivirano]],
            'isteklo juče' => [['rok' => '2026-10-08']],
        ];
    }

    /** @param  array<string, mixed>  $dopuna */
    #[Test]
    #[DataProvider('nejavne')]
    public function nacrt_arhivirano_i_isteklo_ne_ulaze_u_odgovor(array $dopuna): void
    {
        $this->prilika($dopuna);
        $this->model(['vrsta' => 'posao', 'grad' => null, 'kome' => null, 'kljucne_reci' => []]);

        $this->assertTrue($this->pitaj('Ima li posla?')->nemaPodatak());
    }

    // Ogledalo: isti zapis sa rokom danas ili stalno otvoren ulazi.
    #[Test]
    public function rok_danas_i_stalno_otvoreno_ulaze_u_odgovor(): void
    {
        $this->prilika(['naslov' => 'Danas', 'rok' => '2026-10-09']);
        $this->prilika(['naslov' => 'Stalno', 'rok' => '2026-10-01', 'rok_stalno_otvoren' => true]);
        $this->model(['vrsta' => 'posao', 'grad' => null, 'kome' => null, 'kljucne_reci' => []]);

        $this->assertSame(['Danas', 'Stalno'], $this->pitaj('Ima li posla?')->zapisi->pluck('naslov')->all());
    }

    #[Test]
    public function vrednosti_koje_ne_prolaze_proveru_se_odbacuju_pa_nema_ni_pretrage(): void
    {
        $this->prilika();
        // Vrsta van spiska, grad kog nema u bazi, reč i „kome" kojih nema u pitanju.
        $this->model(['vrsta' => 'volontiranje', 'grad' => 'Subotica', 'kome' => 'Romi', 'kljucne_reci' => ['skladištu', 'knjigovođa']]);

        $rezultat = $this->pitaj('Ima li nečega?');

        $this->assertTrue($rezultat->formular->jePrazan());
        $this->assertTrue($rezultat->nemaPodatak());
    }

    // Ogledalo: iste vrednosti koje piše u pitanju i postoje u bazi prolaze.
    #[Test]
    public function iste_vrednosti_prolaze_kad_pisu_u_pitanju_i_postoje_u_bazi(): void
    {
        $nis = $this->prilika(['opis' => 'Rad u skladištu. Konkurs je otvoren za Rome.']);
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => 'Rome', 'kljucne_reci' => ['skladištu']]);

        $rezultat = $this->pitaj('Ima li posla u Nišu za Rome u skladištu?');

        $this->assertSame('Rome', $rezultat->formular->kome);
        $this->assertSame(['skladištu'], $rezultat->formular->kljucneReci);
        $this->assertSame([$nis->id], $rezultat->zapisi->pluck('id')->all());
    }

    // Pitanje o gradu kog u bazi nema nije pitanje o drugom gradu: grad se ne odbacuje, pa nema ni tuđih prilika.
    #[Test]
    public function grad_iz_pitanja_kog_nema_u_bazi_ne_pokazuje_prilike_iz_drugih_gradova(): void
    {
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Subotica', 'kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ima li posla u Subotici?');

        $this->assertTrue($rezultat->nemaPodatak());
        $this->assertSame('Subotica', $rezultat->formular->grad);
    }

    // Ogledalo: grad koji model izmisli, a u pitanju ga nema, odbacuje se i ne sužava pretragu.
    #[Test]
    public function grad_kog_nema_u_pitanju_se_odbacuje_i_kad_postoji_u_bazi(): void
    {
        $nis = $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ima li posla?');

        $this->assertNull($rezultat->formular->grad);
        $this->assertSame([$nis->id], $rezultat->zapisi->pluck('id')->all());
    }

    // Padež u pitanju ne smeta: „Novom Sadu" prema „Novi Sad" u bazi.
    #[Test]
    public function grad_se_prepoznaje_u_bilo_kom_padezu(): void
    {
        $ns = $this->prilika(['mesto' => 'Novi Sad']);
        $this->model(['vrsta' => 'posao', 'grad' => 'Novi Sad', 'kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ima li posla u Novom Sadu?');

        $this->assertSame('Novi Sad', $rezultat->formular->grad);
        $this->assertSame([$ns->id], $rezultat->zapisi->pluck('id')->all());
    }

    // Model u ključne reči stavlja i „posla" (padež reči „posao"); to ne sme da obori pretragu.
    #[Test]
    public function reci_koje_ponavljaju_vrstu_ili_grad_ne_suzavaju_pretragu(): void
    {
        $nis = $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => ['posla', 'Nišu']]);

        $rezultat = $this->pitaj('Ima li posla u Nišu?');

        $this->assertSame([], $rezultat->formular->kljucneReci);
        $this->assertSame([$nis->id], $rezultat->zapisi->pluck('id')->all());
    }

    // Ogledalo: reč koja nešto dodaje ostaje i sužava pretragu.
    #[Test]
    public function reci_koje_nesto_dodaju_ostaju_u_formularu(): void
    {
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => ['posla', 'vozače']]);

        $rezultat = $this->pitaj('Ima li posla za vozače u Nišu?');

        $this->assertSame(['vozače'], $rezultat->formular->kljucneReci);
        $this->assertTrue($rezultat->nemaPodatak());
    }

    #[Test]
    public function grad_koji_je_model_napisao_u_padezu_se_prepoznaje_kao_grad_iz_baze(): void
    {
        $kg = $this->prilika(['mesto' => 'Kragujevac']);
        $this->model(['vrsta' => 'posao', 'grad' => 'Kragujevc', 'kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ima li posla u Kragujevcu?');

        $this->assertSame('Kragujevac', $rezultat->formular->grad);
        $this->assertSame([$kg->id], $rezultat->zapisi->pluck('id')->all());
    }

    #[Test]
    public function kljucna_rec_koje_nema_u_prilici_znaci_nemam_podatak(): void
    {
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => ['knjigovođa']]);

        $this->assertTrue($this->pitaj('Ima li posla u Nišu za knjigovođa?')->nemaPodatak());
    }

    #[Test]
    public function kome_se_trazi_kao_rec_u_tekstu_prilike(): void
    {
        $this->prilika(['naslov' => 'Za sve', 'opis' => 'Otvoreno za sve.']);
        $namenjena = $this->prilika(['naslov' => 'Za Rome', 'opis' => 'Prijave Roma su dobrodošle.']);
        $this->model(['vrsta' => 'posao', 'grad' => null, 'kome' => 'Romi', 'kljucne_reci' => []]);

        $this->assertSame([$namenjena->id], $this->pitaj('Ima li posla za Romi?')->zapisi->pluck('id')->all());
    }

    #[Test]
    public function nema_zapisa_znaci_nemam_podatak_i_model_se_zove_samo_jednom(): void
    {
        $this->prilika();
        $this->model(['vrsta' => 'stipendija', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]);

        $rezultat = $this->pitaj('Ima li stipendija u Nišu?');

        $this->assertTrue($rezultat->nemaPodatak());
        $this->assertSame(Pomocnik::NEMAM_PODATAK, 'Nemam podatak.');
        $this->assertSame(1, $this->pozivaModela());
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function pitanjaBezOdgovora(): array
    {
        return [
            'vrsta kojoj nema zapisa' => ['Ima li stipendija?', ['vrsta' => 'stipendija']],
            'grad kog nema' => ['Ima li posla u Subotici?', ['vrsta' => 'posao', 'grad' => 'Subotica']],
            'izmišljena reč' => ['Ima li posla za pilote?', ['vrsta' => 'posao', 'kljucne_reci' => ['pilote']]],
            'pitanje van teme' => ['Kolika je visina Mont Everesta?', []],
            'model izmišlja sve' => ['Šta ima novo?', ['vrsta' => 'konkurs', 'grad' => 'Niš', 'kome' => 'Romi', 'kljucne_reci' => ['pilot']]],
            'prazan formular' => ['Zdravo', ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []]],
        ];
    }

    /** @param  array<string, mixed>  $forma */
    #[Test]
    #[DataProvider('pitanjaBezOdgovora')]
    public function pitanja_bez_odgovora_u_bazi_daju_nemam_podatak(string $pitanje, array $forma): void
    {
        $this->prilika();
        $this->model($forma);

        $this->assertTrue($this->pitaj($pitanje)->nemaPodatak());
        $this->assertSame(1, $this->pozivaModela());
    }

    #[Test]
    public function odgovor_modela_koji_nije_json_je_prazan_formular_a_ne_greska(): void
    {
        $this->prilika();
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => 'Evo šta mislim: ima posla.']])]);

        $this->assertTrue($this->pitaj('Ima li posla?')->nemaPodatak());
    }

    #[Test]
    public function kad_ollama_ne_radi_izuzetak_ide_pozivaocu(): void
    {
        Http::fake([self::OLLAMA => fn () => throw new ConnectionException('nema veze')]);

        $this->expectException(OllamaNedostupna::class);

        $this->pitaj('Ima li posla?');
    }

    #[Test]
    public function prazno_pitanje_ne_zove_model(): void
    {
        Http::fake();

        $this->assertTrue($this->pitaj('   ')->nemaPodatak());
        Http::assertNothingSent();
    }

    #[Test]
    public function cirilica_i_pitanje_bez_dijakritika_se_razumeju(): void
    {
        $nis = $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Nis', 'kome' => null, 'kljucne_reci' => []]);

        $this->assertSame([$nis->id], $this->pitaj('Ima li posla u Nisu?')->zapisi->pluck('id')->all());

        $this->model(['vrsta' => 'posao', 'grad' => 'Ниш', 'kome' => null, 'kljucne_reci' => []]);
        $this->pitaj('Има ли посла у Нишу?');

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA
            && str_contains($zahtev->data()['messages'][1]['content'], 'Ima li posla u Nišu?')
            && preg_match('/\p{Cyrillic}/u', $zahtev->data()['messages'][1]['content']) === 0);
    }

    #[Test]
    public function najvise_pet_zapisa_najbliži_rok_prvi(): void
    {
        foreach ([7, 3, 5, 1, 6, 2, 4] as $dan) {
            $this->prilika(['naslov' => "Posao $dan", 'rok' => '2026-11-0'.$dan]);
        }
        $this->model(['vrsta' => 'posao', 'grad' => null, 'kome' => null, 'kljucne_reci' => []]);

        $this->assertSame(['Posao 1', 'Posao 2', 'Posao 3', 'Posao 4', 'Posao 5'], $this->pitaj('Ima li posla?')->zapisi->pluck('naslov')->all());
    }

    #[Test]
    public function pitanje_se_skracuje_na_granicu_pre_slanja_modelu(): void
    {
        $this->model(['vrsta' => null]);

        $this->pitaj(str_repeat('a', 1000));

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA
            && mb_strlen($zahtev->data()['messages'][1]['content']) === Pomocnik::NAJVISE_ZNAKOVA_PITANJA);
    }

    #[Test]
    public function uputstvo_modelu_trazi_formular_a_ne_odgovor(): void
    {
        $this->model(['vrsta' => null]);

        $this->pitaj('Ima li posla?');

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA
            && $zahtev->data()['options']['temperature'] === 0
            && str_contains($zahtev->data()['messages'][0]['content'], 'Ne odgovaraš na pitanje.')
            && str_contains($zahtev->data()['messages'][0]['content'], 'kljucne_reci'));
    }
}
