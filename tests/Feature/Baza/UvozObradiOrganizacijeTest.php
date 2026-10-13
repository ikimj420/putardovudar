<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 74/T3 (Ivan, 13.10.2026.): „organizacije koje…" dokazuje grupu „Organizacije" samo uz rad sa ljudima („koje rade sa mladima", „koje se bave zaštitom…"), ne i „organizacijama koje žele
// da istraže…". Strane su isečci pravih strana (tests/Fixtures/stranice/): GOTOTWIN poziv (preduzeća, startapi, istraživačke ustanove) i Erasmus+ mentorski program (omladinske organizacije).
#[Group('baza')]
class UvozObradiOrganizacijeTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const GOTOTWIN = 'https://eumogucnosti.rs/gototwin-poziv-testiranje-digitalnih-resenja-za-obnovljive-izvore-energije/';

    private const MENTORSKI = 'https://erasmusplus.rs/podrska-organizacijama-koje-rade-sa-mladima/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
    }

    /** @param  array<string, mixed>  $forma */
    private function obradi(string $naslov, string $adresa, string $snimak, array $forma): Prilika
    {
        $nacrt = Prilika::factory()->create(['naslov' => $naslov, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => $adresa]);
        Http::fake([
            $adresa => Http::response((string) file_get_contents(base_path('tests/Fixtures/stranice/'.$snimak)), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            self::OLLAMA => Http::response(['message' => ['content' => json_encode($forma, JSON_UNESCAPED_UNICODE)]]),
        ]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        return $nacrt->fresh();
    }

    // Ono što je model citirao kao dokaz grupe na GOTOTWIN strani (izmereno u radnoj bazi): „organizacijama koje žele da istraže…". To nisu udruženja, pa grupa nije dokazana.
    #[Test]
    public function gototwin_poziv_za_preduzeca_i_istrazivacke_ustanove_nema_grupu_organizacije(): void
    {
        $prilika = $this->obradi('GOTOTWIN poziv: Testiranje digitalnih rešenja za obnovljive izvore energije', self::GOTOTWIN, 'gototwin.html', [
            'vazi_pravilo' => true, 'razlog' => 'Poziv za organizacije.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Poziv je otvoren do 20. oktobra 2026. godine',
            'grupe' => [['grupa' => 'organizacije', 'citat' => 'Otvoreni poziv za eksperimente namenjen je organizacijama koje žele da istraže, provere ili demonstriraju primenu digitalnih blizanaca u sistemima obnovljive energije.']],
        ]);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('pravilo nije potkrepljeno citatom', $prilika->predlog['odbijeno']);
        $this->assertSame([], $prilika->predlog['prihvacene_grupe']);
    }

    // Ni kad model ne navede ništa, pa grupu traži sam kod po strani: u celoj strani nema rečenice koja kaže da je poziv za udruženja ili rad sa ljudima.
    #[Test]
    public function kod_na_gototwin_strani_ne_nalazi_citat_za_organizacije(): void
    {
        $prilika = $this->obradi('GOTOTWIN poziv: Testiranje digitalnih rešenja za obnovljive izvore energije', self::GOTOTWIN, 'gototwin.html', [
            'vazi_pravilo' => true, 'razlog' => 'Poziv.', 'vrsta' => 'konkurs', 'rok' => '2026-10-20', 'citat' => 'Poziv je otvoren do 20. oktobra 2026. godine',
            'grupe' => [['grupa' => 'organizacije', 'citat' => 'Nešto što ne piše na strani']],
        ]);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame([], $prilika->predlog['prihvacene_grupe']);
        $this->assertSame('pravilo nije potkrepljeno citatom', $prilika->predlog['odbijeno']);
    }

    // Ogledalo: Erasmus+ mentorski program („omladinske organizacije i institucije koje rade sa mladima") i dalje dokazuje grupu i objavljuje se.
    #[Test]
    public function erasmus_mentorski_program_za_organizacije_koje_rade_sa_mladima_i_dalje_dokazuje_grupu(): void
    {
        $prilika = $this->obradi('Podrška organizacijama koje rade sa mladima i žele da naprave prvi korak u Erazmus+ programu', self::MENTORSKI, 'erasmus-mentorski-program.html', [
            'vazi_pravilo' => true, 'razlog' => 'Poziv organizacijama.', 'vrsta' => 'konkurs', 'rok' => '2026-11-10', 'citat' => 'Rok za popunjavanje registracionog formulara je 10. novembar 2026. do 23.59 časova.',
            'grupe' => [['grupa' => 'organizacije', 'citat' => 'Fondacija Tempus poziva omladinske organizacije i institucije koje rade sa mladima uzrasta od 13 do 30 godina']],
        ]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame([Grupa::Organizacije->value], $prilika->grupe);
        $this->assertSame('2026-11-10', $prilika->rok->toDateString());
    }

    // Ogledalo: isto i kad se dokaz sastoji samo od „organizacije koje rade sa mladima" (bez „omladinske"), jer to pravilo ostaje.
    #[Test]
    public function samo_organizacije_koje_rade_sa_mladima_dokazuju_grupu(): void
    {
        $prilika = $this->obradi('Podrška organizacijama koje rade sa mladima', self::MENTORSKI, 'erasmus-mentorski-program.html', [
            'vazi_pravilo' => true, 'razlog' => 'Poziv organizacijama.', 'vrsta' => 'konkurs', 'rok' => '2026-11-10', 'citat' => 'Rok za popunjavanje registracionog formulara je 10. novembar 2026. do 23.59 časova.',
            'grupe' => [['grupa' => 'organizacije', 'citat' => 'Podrška organizacijama koje rade sa mladima i žele da naprave prvi korak u Erazmus+ programu']],
        ]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame([Grupa::Organizacije->value], $prilika->grupe);
    }
}
