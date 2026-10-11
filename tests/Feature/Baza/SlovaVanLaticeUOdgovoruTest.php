<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Services\Pomocnik\Pomocnik;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 67/T2: odgovor modela sa slovom koje nije latinica ne stiže do čoveka: pomoćnik daje gotov tekst sa karticama, „Napiši moj" sklopljen šablon.
#[Group('baza')]
class SlovaVanLaticeUOdgovoruTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private string $odgovorModela = '';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15']);
        // Http::fake ostaje na prvom postavljenom odgovoru, pa se postavlja jednom, a odgovor se menja kroz svojstvo.
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => $this->odgovorModela]]);
        }]);
    }

    #[Test]
    public function pomocnik_sa_kineskim_slovima_u_odgovoru_daje_gotov_tekst_i_kartice(): void
    {
        $this->odgovorModela = 'Našao sam posao radnika u skladištu u Nišu i限期等待 user Izvinjavam se, mogao bih da to ponovim?';

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertTrue($odgovor->jeIzSablona());
        $this->assertSame('slova koja nisu latinica', $odgovor->razlogSablona);
        $this->assertStringStartsWith(Pomocnik::SABLON_NASLOV, $odgovor->tekst);
        $this->assertStringContainsString('Radnik u skladištu', $odgovor->tekst);
        $this->assertSame(0, preg_match('/\p{Han}/u', $odgovor->tekst));
        $this->assertSame(['Radnik u skladištu'], array_map(fn ($izvor) => $izvor->naslov, $odgovor->izvori));
    }

    // Ogledalo: isti odgovor bez slova drugog pisma prolazi, a srpska ćirilica se pretvara u latinicu i prolazi.
    #[Test]
    public function isti_odgovor_na_latinici_i_srpska_cirilica_prolaze(): void
    {
        $this->odgovorModela = 'Našao sam posao radnika u skladištu u Nišu.';
        $this->assertSame('Našao sam posao radnika u skladištu u Nišu.', app(Pomocnik::class)->odgovori('Ima li posla u Nišu?')->tekst);

        $this->odgovorModela = "\u{041D}\u{0430}\u{0448}\u{0430}\u{043E} \u{0441}\u{0430}\u{043C} \u{043F}\u{043E}\u{0441}\u{0430}\u{043E}.";
        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertFalse($odgovor->jeIzSablona());
        $this->assertSame('Našao sam posao.', $odgovor->tekst);
    }

    // Slovo koje ostane ćirilica (nije srpska azbuka) ne prolazi.
    #[Test]
    public function cirilica_van_srpske_azbuke_koja_ostane_ne_prolazi(): void
    {
        $this->odgovorModela = "Našao sam posao \u{044B} u Nišu.";

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertSame('slova koja nisu latinica', $odgovor->razlogSablona);
    }

    // Smeće u trećoj rečenici kod već odseca (paket 65), pa do čoveka stiže čist tekst iz prve dve.
    #[Test]
    public function smece_posle_druge_recenice_se_odseca_a_ne_odbija_odgovor(): void
    {
        $this->odgovorModela = 'Našao sam posao u Nišu. Rok je u novembru. Izvinjavam se 限期等待.';

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertFalse($odgovor->jeIzSablona());
        $this->assertSame('Našao sam posao u Nišu. Rok je u novembru.', $odgovor->tekst);
    }

    /** @param  array<string, string>  $polja */
    private function napisi(Sablon $sablon, array $polja): string
    {
        return $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => $polja, 'rod' => 'zenski'])->assertOk()->getContent();
    }

    private function sablon(): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true],
            ],
            'tekst' => "{ime}\n\nMolim: {tekst}",
        ]);
    }

    #[Test]
    public function napisi_moj_sa_kineskim_slovima_u_tekstu_modela_daje_sklopljen_sablon(): void
    {
        $sablon = $this->sablon();
        $this->odgovorModela = "Zoë Krasniqi\n\nMolim: pomoć 限期等待 user Izvinjavam se.";

        $html = $this->napisi($sablon, ['ime' => 'Zoë Krasniqi', 'tekst' => 'pomoć']);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertStringNotContainsString('限期等待', $html);
        $this->assertStringContainsString("Zoë Krasniqi\n\nMolim: pomoć", html_entity_decode($html));
    }

    // Ogledalo: ime sa „ë" i „Mirjeta Krasniqi Gashi" prolaze kad ih model ponovi, jer su latinica.
    #[Test]
    public function imena_sa_znakovima_prolaze_kad_ih_model_ponovi(): void
    {
        $sablon = $this->sablon();

        foreach (['Zoë Krasniqi', 'Mirjeta Krasniqi Gashi'] as $ime) {
            $this->odgovorModela = "{$ime}\n\nMolim: pomoć.";

            $html = $this->napisi($sablon, ['ime' => $ime, 'tekst' => 'pomoć.']);

            $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html, $ime);
            $this->assertStringNotContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html, $ime);
        }
    }
}
