<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Pomocnik\Pomocnik;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 22: oba poziva modela zajedno staju u ukupno vreme za pitanje, da strana uvek vrati odgovor pre prekida veze.
#[Group('baza')]
class PomocnikVremeTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    /** @var list<array{0: string, 1: int}> vrsta poziva i vreme čekanja koje je dobio */
    private array $pozivi = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('ollama.vreme_cekanja', 120);
        config()->set('pomocnik.ukupno_za_pitanje', 50);
        config()->set('pomocnik.najmanje_za_odgovor', 5);

        Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
    }

    /** Model beleži vreme čekanja svakog poziva i „troši" zadati broj sekundi (test premotava sat). */
    private function model(int $formularTraje = 0, int $odgovorTraje = 0, bool $odgovorPada = false, string $grad = 'Niš'): void
    {
        Http::fake([self::OLLAMA => function (Request $zahtev, array $opcije) use ($formularTraje, $odgovorTraje, $odgovorPada, $grad) {
            $jeFormular = array_key_exists('format', $zahtev->data());
            $this->pozivi[] = [$jeFormular ? 'formular' : 'odgovor', $opcije['timeout']];
            $this->travel($jeFormular ? $formularTraje : $odgovorTraje)->seconds();

            if (! $jeFormular && $odgovorPada) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['message' => ['content' => $jeFormular
                ? json_encode(['vrsta' => 'posao', 'grad' => $grad, 'kome' => null, 'kljucne_reci' => []])
                : 'U Nišu ima posla za radnika u skladištu.']]);
        }]);
    }

    #[Test]
    public function prvi_poziv_dobija_polovinu_ukupnog_vremena_a_drugi_ostatak(): void
    {
        $this->model(formularTraje: 10);

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame([['formular', 25], ['odgovor', 40]], $this->pozivi);
        $this->assertSame('U Nišu ima posla za radnika u skladištu.', $odgovor->tekst);
        $this->assertNull($odgovor->razlogSablona);
    }

    #[Test]
    public function oba_poziva_zajedno_ne_prelaze_ukupno_vreme_ni_kad_prvi_potrosi_sve_sto_sme(): void
    {
        $this->model(formularTraje: 25);

        app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertCount(2, $this->pozivi);
        $this->assertSame([['formular', 25], ['odgovor', 25]], $this->pozivi);
        $this->assertLessThanOrEqual(50, $this->pozivi[0][1] + $this->pozivi[1][1]);
    }

    #[Test]
    public function kad_ostane_manje_od_najmanjeg_drugi_poziv_se_ne_pravi_i_stoji_sablon_sa_izvorima(): void
    {
        $this->model(formularTraje: 46);

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame([['formular', 25]], $this->pozivi);
        $this->assertStringStartsWith(Pomocnik::SABLON_NASLOV, $odgovor->tekst);
        $this->assertStringContainsString('Radnik u skladištu', $odgovor->tekst);
        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertCount(1, $odgovor->izvori);
        $this->assertSame('nema vremena', $odgovor->razlogSablona);
    }

    // Ogledalo: na samoj granici (ostaje tačno najmanje) drugi poziv se pravi.
    #[Test]
    public function kad_ostane_tacno_najmanje_drugi_poziv_se_pravi(): void
    {
        $this->model(formularTraje: 45);

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame([['formular', 25], ['odgovor', 5]], $this->pozivi);
        $this->assertNull($odgovor->razlogSablona);
    }

    #[Test]
    public function vreme_cekanja_iz_podesavanja_je_gornja_granica_a_ne_samo_ukupno(): void
    {
        config()->set('ollama.vreme_cekanja', 7);
        $this->model();

        app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame([['formular', 7], ['odgovor', 7]], $this->pozivi);
    }

    #[Test]
    public function ukupno_vreme_iz_podesavanja_menja_podelu(): void
    {
        config()->set('pomocnik.ukupno_za_pitanje', 20);
        $this->model();

        app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame([['formular', 10], ['odgovor', 20]], $this->pozivi);
    }

    // Ogledalo: pitanje bez zapisa ne troši vreme na drugi poziv, ma koliko ga ostalo.
    #[Test]
    public function pitanje_bez_zapisa_ima_samo_jedan_poziv(): void
    {
        $this->model(grad: 'Subotica');

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Subotici?');

        $this->assertSame([['formular', 25]], $this->pozivi);
        $this->assertTrue($odgovor->nemaPodatak);
    }

    #[Test]
    public function strana_vraca_sablon_sa_izvorom_kad_drugi_poziv_ne_stigne_ili_nema_vremena(): void
    {
        foreach ([['formularTraje' => 46], ['formularTraje' => 5, 'odgovorPada' => true]] as $podesavanje) {
            $this->pozivi = [];
            $this->model(...$podesavanje);

            $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();
            $tekst = $this->vidljivTekst($html);

            $this->assertStringContainsString(Pomocnik::SABLON_NASLOV, $tekst);
            $this->assertStringContainsString('Radnik u skladištu', $tekst);
            $this->assertStringContainsString('https://primer.rs/skladiste-nis', $html);
            $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $tekst);
        }
    }

    // Ogledalo: kad ne stigne već prvi poziv, strana kaže da pomoćnik ne radi, ne vraća prazno.
    #[Test]
    public function strana_kaze_da_pomocnik_ne_radi_kad_ne_stigne_prvi_poziv(): void
    {
        Http::fake([self::OLLAMA => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $tekst = $this->vidljivTekst($this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent());

        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $tekst);
    }
}
