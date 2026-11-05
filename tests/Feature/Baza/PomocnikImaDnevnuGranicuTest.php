<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;
use Tests\Podrska\LazniClaude;

// Paket 100/T8 (Ivan, 05.11.2026.): ceo sajt ima dnevnu granicu pitanja i „Napiši moj" (Claude se plaća po upotrebi). Preko granice pomoćnik odgovara kao kad ne radi, a Claude se ne zove.
// Granica po adresi (pomocnik.pitanja_u_minuti) ostaje i važi uz nju.
#[Group('baza')]
class PomocnikImaDnevnuGranicuTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const MODEL = LazniClaude::ADRESA;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
        // Granica po adresi ovde ne smeta: meri se samo dnevna.
        config()->set('pomocnik.pitanja_u_minuti', 1000);
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-12-15', 'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis']);
        $this->model();
    }

    private function model(bool $radi = true): void
    {
        Http::fake([self::MODEL => function (Request $zahtev) use ($radi) {
            if (! $radi) {
                throw new ConnectionException('nema veze');
            }

            return LazniClaude::jeJson($zahtev)
                ? LazniClaude::odgovor(json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]))
                : LazniClaude::odgovor('Našao sam posao u Nišu.');
        }]);
    }

    private function pitaj(string $pitanje = 'Ima li posla u Nišu?', string $adresa = '198.51.100.7'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $adresa])->post(route('pomocnik.pitaj'), ['pitanje' => $pitanje]);
    }

    private function sablon(): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => "Poštovani,\n\n{tekst}\n\nS poštovanjem",
        ]);
    }

    private function napisi(Sablon $sablon): TestResponse
    {
        return $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['tekst' => 'Molim za pomoć.']]);
    }

    private function pozivaModela(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::MODEL)->count();
    }

    #[Test]
    public function pitanja_do_granice_dobijaju_odgovor_a_prvo_preko_nje_pise_kao_kad_ne_radi(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 3);

        foreach ([1, 2, 3] as $broj) {
            $tekst = $this->vidljivTekst($this->pitaj(adresa: '198.51.100.'.$broj)->assertOk()->getContent());

            $this->assertStringContainsString('Radnik u skladištu', $tekst, 'pitanje '.$broj);
            $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $tekst, 'pitanje '.$broj);
        }

        // Svako pitanje je dva poziva (formular i odgovor).
        $this->assertSame(6, $this->pozivaModela());

        $preko = $this->vidljivTekst($this->pitaj(adresa: '198.51.100.4')->assertOk()->getContent());

        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $preko);
        $this->assertStringNotContainsString('Radnik u skladištu', $preko);
        $this->assertSame(6, $this->pozivaModela(), 'preko granice Claude se ne zove');
    }

    // Isti ishod kao kad model stvarno ne radi: ista poruka, isti okvir strane.
    #[Test]
    public function preko_granice_odgovor_je_isti_kao_kad_model_ne_radi(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 0);
        $prekoGranice = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        config()->set('pomocnik.pitanja_dnevno', 300);
        Cache::flush();
        $this->model(radi: false);
        $kadNeRadi = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $prekoGranice);
        $this->assertSame($kadNeRadi, $prekoGranice);
    }

    // Granica je za ceo sajt, ne po adresi: druga adresa je već preko nje.
    #[Test]
    public function granica_je_za_ceo_sajt_a_ne_po_adresi(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 1);

        $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($this->pitaj(adresa: '198.51.100.1')->getContent()));
        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($this->pitaj(adresa: '203.0.113.9')->getContent()));
    }

    // Sutradan brojač kreće ispočetka.
    #[Test]
    public function sutradan_pitanja_opet_rade(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 1);
        $this->pitaj();

        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($this->pitaj()->getContent()));

        $this->travelTo(Carbon::create(2026, 11, 5, 23, 59, 0, 'Europe/Belgrade'));
        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($this->pitaj()->getContent()), 'još je isti dan');

        $this->travelTo(Carbon::create(2026, 11, 6, 0, 1, 0, 'Europe/Belgrade'));
        $posle = $this->vidljivTekst($this->pitaj()->getContent());

        $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $posle);
        $this->assertStringContainsString('Radnik u skladištu', $posle);
    }

    // Računa se samo ono što bi pozvalo model: opasno i prazno pitanje ne troše granicu.
    #[Test]
    public function pitanje_koje_ne_stigne_do_modela_ne_trosi_granicu(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 1);

        $this->pitaj('Kako da lažiram dokument?')->assertOk();
        $this->post(route('pomocnik.pitaj'), ['pitanje' => '   '])->assertStatus(422);
        $this->assertSame(0, $this->pozivaModela());

        // Ogledalo: jedino stvarno pitanje i dalje prolazi, pa tek ono troši granicu.
        $this->assertStringContainsString('Radnik u skladištu', $this->vidljivTekst($this->pitaj()->getContent()));
        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($this->pitaj()->getContent()));
    }

    // „Napiši moj" zove isti Claude, pa deli isti brojač; preko granice stiže složen šablon, kao kad model ne radi.
    #[Test]
    public function napisi_moj_deli_granicu_sa_pitanjima_i_preko_nje_daje_slozen_sablon(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 2);
        $sablon = $this->sablon();

        $this->pitaj()->assertOk();
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $this->vidljivTekst($this->napisi($sablon)->assertOk()->getContent()));
        $pozivaDoGranice = $this->pozivaModela();

        $preko = $this->vidljivTekst($this->napisi($sablon)->assertOk()->getContent());

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $preko);
        $this->assertStringContainsString('Molim za pomoć.', $preko);
        $this->assertSame($pozivaDoGranice, $this->pozivaModela(), 'preko granice Claude se ne zove');
    }

    // U kešu stoji samo broj za taj dan.
    #[Test]
    public function u_kesu_stoji_samo_broj_pitanja_za_taj_dan(): void
    {
        config()->set('pomocnik.pitanja_dnevno', 5);
        $this->pitaj('Ima li posla u Nišu? TRAG-4412');
        $this->pitaj();

        $this->assertSame(2, Cache::get('pomocnik:pitanja:2026-11-05'));
    }
}
