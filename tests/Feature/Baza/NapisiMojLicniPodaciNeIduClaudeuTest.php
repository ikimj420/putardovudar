<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 100/T2 (Ivan, 05.11.2026.): ime i prezime, adresa, telefon i e-pošta ne idu Claude-u u „Napiši moj": model dobija oznake ([IME], [ADRESA], [TELEFON], [EPOSTA]),
// a aplikacija ih posle odgovora upisuje u gotov tekst. Šta čovek upiše u ostala polja i dalje ide modelu (PITANJA-100.md).
#[Group('baza')]
class NapisiMojLicniPodaciNeIduClaudeuTest extends BazaTestCase
{
    use RefreshDatabase;

    private const IME = 'Ana Anić';

    private const ADRESA = 'Ulica Probna 5, Niš';

    private const TELEFON = '064 123 456';

    private const EPOSTA = 'ana.anic@primer.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function sablon(): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Molba', 'objasnjenje' => 'Za molbu.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'adresa', 'oznaka' => 'Adresa', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'telefon', 'oznaka' => 'Telefon', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'email', 'oznaka' => 'E-pošta', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'primalac', 'oznaka' => 'Kome pišeš', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'tekst', 'oznaka' => 'Šta moliš', 'objasnjenje' => null, 'dugo' => true],
            ],
            'tekst' => "{ime}\n{adresa}\n{telefon}\n{email}\n\n{primalac}\n\nPoštovani,\n\n{tekst}\n\nS poštovanjem,\n{ime}",
        ]);
    }

    /** @param  array<string, string>  $dopuna */
    private function napisi(array $dopuna = []): TestResponse
    {
        $unosi = [...['ime' => self::IME, 'adresa' => self::ADRESA, 'telefon' => self::TELEFON, 'email' => self::EPOSTA, 'primalac' => 'Osnovna škola Vuk Karadžić', 'tekst' => 'Molim za pomoć oko upisa.'], ...$dopuna];

        return $this->post(route('pomocnik.napisi', $this->sablon()->slug), ['polje' => $unosi]);
    }

    private function napisan(TestResponse $odgovor): string
    {
        preg_match('#<div class="sklopljen-tekst" id="napisan-tekst">(.*?)</div>#s', (string) $odgovor->getContent(), $nalaz);

        return html_entity_decode($nalaz[1] ?? '');
    }

    /** Sve što je otišlo ka Claude-u, kao jedan tekst: uputstvo, poruka i ostalo iz zahteva. */
    private function poslato(): string
    {
        return Http::recorded()->map(fn (array $par) => json_encode($par[0]->data(), JSON_UNESCAPED_UNICODE))->implode("\n");
    }

    private function model(string $tekst): void
    {
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor($tekst)]);
    }

    #[Test]
    public function modelu_ide_oznaka_umesto_imena_adrese_telefona_i_eposte(): void
    {
        $this->model("[IME]\n[ADRESA]\n[TELEFON]\n[EPOSTA]\n\nOsnovna škola Vuk Karadžić\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]");

        $this->napisi()->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zahtev) {
            $poruka = LazniClaude::poruka($zahtev);

            return str_contains($poruka, "{ime} (Ime i prezime): [IME]\n{adresa} (Adresa): [ADRESA]\n{telefon} (Telefon): [TELEFON]\n{email} (E-pošta): [EPOSTA]")
                && str_contains(LazniClaude::uputstvo($zahtev), '[IME], [ADRESA], [TELEFON], [EPOSTA]');
        });

        foreach ([self::IME, self::ADRESA, self::TELEFON, self::EPOSTA, '064', 'Anić', 'Ulica Probna', 'primer.test'] as $licno) {
            $this->assertStringNotContainsString($licno, $this->poslato(), $licno);
        }
    }

    #[Test]
    public function aplikacija_upisuje_pravi_podatak_umesto_svake_oznake_u_gotov_tekst(): void
    {
        $this->model("[IME]\n[ADRESA]\n[TELEFON]\n[EPOSTA]\n\nOsnovna škola Vuk Karadžić\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]");

        $odgovor = $this->napisi()->assertOk();

        $this->assertSame(self::IME."\n".self::ADRESA."\n".self::TELEFON."\n".self::EPOSTA."\n\nOsnovna škola Vuk Karadžić\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n".self::IME, $this->napisan($odgovor));
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, (string) $odgovor->getContent());
        $this->assertStringNotContainsString('[IME]', $this->napisan($odgovor));
    }

    // Ime upisano ćirilicom stiže u tekst latinicom, kao i ostatak odgovora (isto kao pre oznaka), a modelu opet ide samo oznaka.
    #[Test]
    public function cirilica_se_upisuje_latinicom_a_modelu_ide_samo_oznaka(): void
    {
        $this->model("[IME]\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]");

        $odgovor = $this->napisi(['ime' => 'Ана Анић', 'adresa' => '', 'telefon' => '', 'email' => '', 'primalac' => '']);

        $this->assertStringContainsString("Ana Anić\n\nPoštovani,", $this->napisan($odgovor));
        $this->assertStringNotContainsString('Ана', $this->poslato());
        $this->assertStringNotContainsString('Анић', $this->poslato());
    }

    // Ogledalo: ono što nije lično polje ide modelu doslovno (primalac, tekst); to je ono što se zapisuje u PITANJA-100.md.
    #[Test]
    public function ostala_polja_idu_modelu_kakva_su(): void
    {
        $this->model('x');

        $this->napisi(['primalac' => 'Osnovna škola Vuk Karadžić', 'tekst' => 'Molim za pomoć oko upisa.'])->assertOk();

        $this->assertStringContainsString('Osnovna škola Vuk Karadžić', $this->poslato());
        $this->assertStringContainsString('Molim za pomoć oko upisa.', $this->poslato());
    }

    /** @return array<string, array{string, string}> */
    public static function oznakeKojimaNemaVrednosti(): array
    {
        return [
            'telefon nije upisan, a model ga izmisli' => ['telefon', "[IME]\n[TELEFON]\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]"],
            'adresa nije upisana, a model je izmisli' => ['adresa', "[IME]\n[ADRESA]\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]"],
            'e-pošta nije upisana, a model je izmisli' => ['email', "[IME]\n[EPOSTA]\n\nPoštovani,\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]"],
        ];
    }

    #[Test]
    #[DataProvider('oznakeKojimaNemaVrednosti')]
    public function oznaka_bez_vrednosti_odbija_tekst_i_stoji_slozen_sablon(string $prazno, string $odgovorModela): void
    {
        $this->model($odgovorModela);

        $odgovor = $this->napisi([$prazno => ''])->assertOk();

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, (string) $odgovor->getContent());
        $this->assertStringNotContainsString('[', $this->napisan($odgovor));
        $this->assertStringContainsString(self::IME, $this->napisan($odgovor));
    }

    #[Test]
    public function oznaka_napisana_drugim_slovima_se_ne_prima(): void
    {
        $this->model("[IME]\n\nPoštovani,\n\nMolim za pomoć oko upisa. Pozovite me na [Telefon].\n\nS poštovanjem,\n[IME]");

        $odgovor = $this->napisi(['adresa' => '', 'telefon' => '', 'email' => '', 'primalac' => ''])->assertOk();

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, (string) $odgovor->getContent());
        $this->assertStringNotContainsString('[', $this->napisan($odgovor));
    }

    // Paket 100/T4: beleška „Ovi podaci ne idu Claude-u…" stoji jednom, odmah ispod poslednjeg ličnog polja; uz primaoca i tekst, koji idu modelu, je nema.
    #[Test]
    public function beleska_o_licnim_podacima_stoji_jednom_ispod_poslednjeg_licnog_polja(): void
    {
        $beleska = '<p class="privatnost">'.PrikazPomocnika::LICNI_PODACI.'</p>';
        $sablon = $this->sablon();

        $forma = $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent();
        $this->assertSame(1, substr_count($forma, $beleska));
        $this->assertMatchesRegularExpression('#name="polje\[email\]"[^>]*>\s*'.preg_quote($beleska, '#').'\s*</div>\s*<div class="polje">\s*<label for="polje-primalac">#', $forma);

        // I posle slanja, kad se obrazac ispiše uz gotov tekst.
        $this->model("[IME]\n\nMolim za pomoć oko upisa.\n\nS poštovanjem,\n[IME]");
        $this->assertSame(1, substr_count((string) $this->napisi()->assertOk()->getContent(), $beleska));
    }

    #[Test]
    public function beleska_ide_ispod_jedinog_licnog_polja_a_bez_njih_je_nema(): void
    {
        $samoIme = Sablon::factory()->objavljen()->create([
            'naslov' => 'Kratko', 'objasnjenje' => 'Kratko.',
            'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => "{tekst}\n{ime}",
        ]);
        $bezLicnih = Sablon::factory()->objavljen()->create([
            'naslov' => 'Bez ličnih', 'objasnjenje' => 'Bez ličnih.',
            'polja' => [['kljuc' => 'primalac', 'oznaka' => 'Kome', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => "{primalac}\n{tekst}",
        ]);

        $prva = $this->get(route('pomocnik.napisi.forma', $samoIme->slug))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="polje\[ime\]"[^>]*>\s*<p class="privatnost">'.preg_quote(PrikazPomocnika::LICNI_PODACI, '#').'</p>\s*</div>\s*<div class="polje">\s*<label for="polje-tekst">#', $prva);

        $this->get(route('pomocnik.napisi.forma', $bezLicnih->slug))->assertOk()->assertDontSee(PrikazPomocnika::LICNI_PODACI);
    }
}
