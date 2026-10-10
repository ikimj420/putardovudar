<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Models\Vodic;
use App\Services\Pomocnik\Pomocnik;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 55: pomoćnik pluta dole desno na svakoj javnoj strani i otvara se kao prozor na istoj strani. Isti pomoćnik kao /pomocnik: ista pravila, ista
// provera i isto ograničenje pitanja; ponašanje u pregledaču (Esc, fokus, telefon) je izmereno u pregledaču, a ovde se čuva sklop.
#[Group('baza')]
class PlutajuciPomocnikTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const FRAGMENT = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 9, 12, 0, 0, 'Europe/Belgrade'));
        $this->postaviModel();
    }

    private function prilika(): Prilika
    {
        return Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
    }

    private string $odgovorModela = 'Postoji posao radnika u skladištu, prijave su do 15. novembra 2026.';

    private bool $modelRadi = true;

    // Jedan lažni model za ceo test (ponovljen Http::fake se ne menja); ono što vraća se menja svojstvima.
    private function postaviModel(): void
    {
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            if (! $this->modelRadi) {
                throw new ConnectionException('nema veze');
            }

            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => $this->odgovorModela]]);
        }]);
    }

    private function fragment(string $pitanje = 'Ima li posla u Nišu?'): TestResponse
    {
        return $this->post(route('pomocnik.pitaj'), ['pitanje' => $pitanje], self::FRAGMENT);
    }

    /** @return array<string, string> ime => adresa svake javne strane (osim strane pomoćnika) */
    private function javneStrane(): array
    {
        $prilika = $this->prilika();
        $vodic = Vodic::factory()->objavljen()->create();
        $organizacija = Organizacija::factory()->objavljena()->create();
        $sablon = Sablon::factory()->objavljen()->create();
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-01-26']);

        return [
            'početna' => route('pocetna'), 'prilike' => route('prilike.index'), 'prilika' => route('prilike.show', $prilika->slug),
            'vodiči' => route('vodici.index'), 'vodič' => route('vodici.show', $vodic->slug),
            'organizacije' => route('organizacije.index'), 'organizacija' => route('organizacije.show', $organizacija->slug),
            'kalendar' => route('kalendar.index'), 'šabloni' => route('sabloni.index'), 'šablon' => route('sabloni.show', $sablon->slug),
            'nepostojeća' => url('/nema-te-strane'),
        ];
    }

    // Dugme je na svakoj javnoj strani (ogledalo: na strani pomoćnika nema drugog, jer je ona sama pomoćnik), kao obična veza ka strani pomoćnika.
    #[Test]
    public function dugme_je_na_svakoj_javnoj_strani_kao_veza_ka_strani_pomocnika(): void
    {
        $obidjeno = 0;

        foreach ($this->javneStrane() as $ime => $adresa) {
            $odgovor = $this->get($adresa);
            $html = $odgovor->getContent();
            $obidjeno++;

            $this->assertSame(1, substr_count($html, '<a class="plutajuci-dugme" href="'.route('pomocnik.index').'" data-pomocnik-otvori aria-controls="pomocnik-prozor">'.PrikazPomocnika::NASLOV.'</a>'), $ime);
            // Prozor je skriven dok skript ne radi, i nosi isti obrazac kao strana pomoćnika.
            $this->assertStringContainsString('<section class="plutajuci-prozor" id="pomocnik-prozor" role="dialog" aria-labelledby="pomocnik-naslov"', $html, $ime);
            $this->assertMatchesRegularExpression('#<section class="plutajuci-prozor"[^>]* hidden>#', $html, $ime);
            $this->assertStringContainsString('<form class="pitanje plutajuci-forma" method="post" action="'.route('pomocnik.pitaj').'" data-pomocnik-forma>', $html, $ime);
            $this->assertStringContainsString('name="_token"', $html, $ime);
            $this->assertStringContainsString('maxlength="'.Pomocnik::NAJVISE_ZNAKOVA_PITANJA.'"', $html, $ime);
            $this->assertStringContainsString('data-pomocnik-zatvori>'.PrikazPomocnika::ZATVORI.'</button>', $html, $ime);
            // Glava prozora nije header: drugi banner na strani obara pristupačnost.
            $this->assertStringContainsString('<div class="plutajuci-glava">', $html, $ime);
            $this->assertSame(1, substr_count($html, '<header'), $ime);
            // Stavka „Pomoćnik" u meniju otvara isti prozor.
            $this->assertStringContainsString('<a class="asistent" href="'.route('pomocnik.index').'" data-pomocnik-otvori', $html, $ime);
        }

        $this->assertGreaterThanOrEqual(11, $obidjeno);

        $html = $this->get(route('pomocnik.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('plutajuci', $html);
        $this->assertSame(1, substr_count($html, 'name="pitanje"'));
        $this->assertSame(0, substr_count($html, 'data-pomocnik-otvori'));
    }

    // Plutajući prozor traži odgovor bez okvira strane (samo odgovor, izvori i upozorenje); obična strana ostaje cela.
    #[Test]
    public function zahtev_iz_prozora_dobija_samo_odgovor_a_obican_zahtev_celu_stranu(): void
    {
        $this->prilika();

        $fragment = $this->fragment()->assertOk()->getContent();
        $strana = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('<header', $fragment);
        $this->assertStringNotContainsString('plutajuci', $fragment);
        $this->assertStringContainsString('<section class="kartica odgovor">', $fragment);
        $this->assertStringContainsString('Postoji posao radnika u skladištu, prijave su do 15. novembra 2026.', $fragment);
        $this->assertStringContainsString('<a class="kartica-veza" href="'.route('prilike.show', Prilika::query()->value('slug')).'">Radnik u skladištu</a>', $fragment);
        $this->assertStringContainsString(PrikazPomocnika::NASLOV_IZVORA, $fragment);
        $this->assertStringContainsString('<p class="izvor-upozorenje">', $fragment);
        $this->assertStringContainsString('<html', $strana);
        $this->assertStringContainsString('<header class="zaglavlje">', $strana);
    }

    // Isti pomoćnik: isti odgovor, isti izvori i ista provera u prozoru i na strani. Izmišljen datum ne prolazi ni u jednom (šablon sa izvorima).
    #[Test]
    public function prozor_i_strana_daju_isti_odgovor_i_istu_proveru(): void
    {
        $this->prilika();

        foreach (['Postoji posao radnika u skladištu, prijave su do 15. novembra 2026.', 'Prijave su do 3. januara 2027.'] as $tekstModela) {
            $this->odgovorModela = $tekstModela;
            $fragment = $this->vidljivTekst($this->fragment()->assertOk()->getContent());
            $strana = $this->vidljivTekst($this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent());

            foreach ([$fragment, $strana] as $tekst) {
                $this->assertStringContainsString(str_contains($tekstModela, '2027') ? 'Našao sam sledeće prilike:' : $tekstModela, $tekst);
                $this->assertSame(str_contains($tekstModela, '2027'), ! str_contains($tekst, $tekstModela));
                $this->assertStringContainsString('Radnik u skladištu', $tekst);
            }
        }
    }

    // Greške stižu kao poruke u prozoru, istim tekstovima kao na strani: prazno pitanje, predugo pitanje, model ne radi i preveliki broj pitanja.
    #[Test]
    public function greske_stizu_kao_poruke_u_prozoru_istim_tekstovima_kao_na_strani(): void
    {
        $prazno = $this->fragment('')->assertStatus(422)->getContent();
        $dugo = $this->fragment(str_repeat('a', Pomocnik::NAJVISE_ZNAKOVA_PITANJA + 1))->assertStatus(422)->getContent();

        $this->assertStringContainsString('<p class="greska" role="alert">'.PrikazPomocnika::GRESKA_PRAZNO.'</p>', $prazno);
        $this->assertStringContainsString('<p class="greska" role="alert">'.PrikazPomocnika::GRESKA_DUGACKO.'</p>', $dugo);
        $this->assertStringNotContainsString('<html', $prazno);

        $this->modelRadi = false;
        $nedostupan = $this->fragment()->assertOk()->getContent();
        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $nedostupan);
        $this->assertStringNotContainsString('<html', $nedostupan);
    }

    // Isto ograničenje pitanja kao na strani: broji se zajedno (isti ključ), a preko granice prozor dobija poruku, ne celu stranu; model se ne zove.
    #[Test]
    public function ogranicenje_pitanja_je_isto_i_vazi_zajedno_za_prozor_i_stranu(): void
    {
        foreach (range(1, 3) as $broj) {
            $this->fragment()->assertOk();
        }
        foreach (range(1, 2) as $broj) {
            $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk();
        }
        $poslato = count(Http::recorded());

        $prozor = $this->fragment()->assertStatus(429)->getContent();
        $this->assertStringContainsString('<p class="greska" role="alert">'.PrikazPomocnika::PREVISE_PITANJA.'</p>', $prozor);
        $this->assertStringNotContainsString('<html', $prozor);
        $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertStatus(429)->assertSee('<html', false);
        $this->assertCount($poslato, Http::recorded());
    }

    // Skript: prozor se otvara iz dugmeta i iz menija, Esc zatvara i vraća fokus, na telefonu fokus ostaje u prozoru, a obrazac se šalje istom
    // putanjom; kad ne uspe, šalje se običnim putem. Bez spoljnih adresa.
    #[Test]
    public function skript_otvara_zatvara_vraca_fokus_i_salje_isti_obrazac(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        foreach ([
            "document.getElementById('pomocnik-prozor')", "document.querySelectorAll('[data-pomocnik-otvori]')", 'dogadjaj.preventDefault();', "dogadjaj.key === 'Escape' && jeOtvoren()",
            'meta.focus();', "document.querySelector('.plutajuci-dugme')].filter(vidljiv)[0];", "window.matchMedia('(max-width: 719px)')", "prozor.setAttribute('aria-modal', 'true');", 'e.inert = true;', "dogadjaj.key !== 'Tab'",
            'fetch(forma.action, {', "'X-Requested-With': 'XMLHttpRequest'", 'new FormData(forma)', "credentials: 'same-origin'", 'forma.submit();', 'rezultat.focus();',
            "prozor.getAttribute('data-trazim')",
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $skript);
        $this->assertStringNotContainsString('eval(', $skript);
    }

    // Stil: dugme je dole desno i ima najmanje 44 px; prozor na telefonu je preko celog ekrana, a na laptopu dole desno; skriven prozor se ne crta;
    // sadržaj ima razmak ispod da dugme ne pokrije kraj strane; u štampi nema dugmeta.
    #[Test]
    public function stil_postavlja_dugme_dole_desno_a_prozor_na_ceo_ekran_na_telefonu(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.plutajuci \{[^}]*position: fixed;[^}]*right: 16px;[^}]*bottom: 16px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-dugme \{[^}]*min-height: 48px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-prozor \{[^}]*position: fixed;[^}]*right: 16px;[^}]*bottom: 16px;[^}]*width: 380px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-prozor\[hidden\] \{\s*display: none;/', $stil);
        $this->assertMatchesRegularExpression('/@media \(max-width: 719px\) \{\s*\.plutajuci-prozor \{\s*inset: 0;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-zatvori \{[^}]*min-height: 44px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.sadrzaj \{[^}]*padding-bottom: 96px;/', $stil);
        $this->assertMatchesRegularExpression('/\.plutajuci[,\s][^}]*display: none;/', substr($stil, (int) strpos($stil, '@media print')));
    }
}
