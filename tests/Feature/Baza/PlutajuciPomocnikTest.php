<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
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
        KalendarStavka::factory()->objavljena()->create(['otvoreno_do' => '2026-01-26']);

        return [
            'početna' => route('pocetna'), 'prilike' => route('prilike.index'), 'prilika' => route('prilike.show', $prilika->slug),
            'vodiči' => route('vodici.index'), 'vodič' => route('vodici.show', $vodic->slug),
            'organizacije' => route('organizacije.index'), 'organizacija' => route('organizacije.show', $organizacija->slug),
            'kalendar' => route('kalendar.index'),
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

            // Dugme se zove „Pitaj Vudar pomoćnika" (Ivan, 10.10.2026.), a prozor „Vudar pomoćnik".
            $this->assertSame(1, preg_match('#<aside class="plutajuci" aria-label="'.PrikazPomocnika::NASLOV.'" data-pomocnik>\s*<a class="plutajuci-dugme" href="'.preg_quote(route('pomocnik.index'), '#').'" data-pomocnik-otvori aria-controls="pomocnik-prozor"><span class="plutajuci-krug" aria-hidden="true"><svg [^>]*focusable="false">.*?</svg></span>'.PrikazPomocnika::PITAJ_VUDAR.'</a>#s', $html), $ime);
            $this->assertStringContainsString('<h2 id="pomocnik-naslov">'.PrikazPomocnika::NASLOV.'</h2>', $html, $ime);
            // Dugme je u svom orijentiru (aside), pa nije slobodan sadržaj izvan orijentira (axe: region).
            $this->assertSame(1, substr_count($html, '</aside>'), $ime);
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
            // Prozor je razgovor: poruke su u dnevniku (role=log) sa porukom dobrodošlice, a polje ima natpis samo za čitače ekrana i jedan red.
            $this->assertStringContainsString('<div class="plutajuci-rezultat razgovor" data-pomocnik-rezultat tabindex="-1" role="log" aria-live="polite">', $html, $ime);
            $this->assertStringContainsString('<div class="poruka poruka-pomocnik"><p>'.PrikazPomocnika::UVOD.'</p></div>', $html, $ime);
            $this->assertStringContainsString('<label class="samo-citac" for="pomocnik-pitanje">'.PrikazPomocnika::OZNAKA_POLJA.'</label>', $html, $ime);
            $this->assertMatchesRegularExpression('#<textarea id="pomocnik-pitanje" name="pitanje" rows="1" maxlength="'.Pomocnik::NAJVISE_ZNAKOVA_PITANJA.'" required placeholder="[^"]*"></textarea>#', $html, $ime);
            // U zaglavlju nema dugmeta za pomoćnika: otvaraju ga samo dugme dole desno i dugme u naslovnom delu početne.
            preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
            $this->assertStringNotContainsString('data-pomocnik-otvori', $zaglavlje[1] ?? '', $ime);
            $this->assertSame($ime === 'početna' ? 2 : 1, substr_count($html, 'data-pomocnik-otvori aria-controls="pomocnik-prozor"'), $ime);
        }

        $this->assertGreaterThanOrEqual(9, $obidjeno);

        $html = $this->get(route('pomocnik.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('plutajuci', $html);
        $this->assertSame(1, substr_count($html, 'name="pitanje"'));
        $this->assertSame(0, substr_count($html, 'data-pomocnik-otvori'));
    }

    // Plutajući prozor traži odgovor bez okvira strane: jedna poruka pomoćnika bez velikih naslova „Odgovor" i „Izvori", sa izvorima kao malim karticama
    // (naslov običnim slovima, rok, zvanični izvor); obična strana ostaje cela, sa naslovima.
    #[Test]
    public function zahtev_iz_prozora_dobija_samo_odgovor_a_obican_zahtev_celu_stranu(): void
    {
        $this->prilika();

        $fragment = $this->fragment()->assertOk()->getContent();
        $strana = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $fragment);
        $this->assertStringNotContainsString('<header', $fragment);
        $this->assertStringNotContainsString('plutajuci', $fragment);
        $this->assertStringContainsString('<div class="poruka poruka-pomocnik">', $fragment);
        $this->assertStringContainsString('Postoji posao radnika u skladištu, prijave su do 15. novembra 2026.', $fragment);
        // Bez velikih naslova: nijedan naslov u poruci, ni reči „Odgovor" i „Izvori".
        $this->assertDoesNotMatchRegularExpression('#<h[1-6]#', $fragment);
        $this->assertStringNotContainsString(PrikazPomocnika::NASLOV_ODGOVORA, $fragment);
        $this->assertStringNotContainsString(PrikazPomocnika::NASLOV_IZVORA, $fragment);
        // Izvor je mala kartica: naslov (veza preko cele kartice), rok i zvanični izvor.
        $slug = Prilika::query()->value('slug');
        $this->assertSame(1, substr_count($fragment, '<article class="kartica izvor-mala">'));
        $this->assertStringContainsString('<p class="izvor-naslov"><a class="kartica-veza" href="'.route('prilike.show', $slug).'">Radnik u skladištu</a></p>', $fragment);
        $this->assertStringContainsString('<p class="izvor-podatak">Rok: 15.11.2026.</p>', $fragment);
        $this->assertMatchesRegularExpression('#<p class="izvor-podatak">\s*Zvanični izvor:\s*<a href="https://primer\.rs/skladiste-nis" rel="noopener noreferrer nofollow" target="_blank">Probni izvor</a>\s*</p>#', $fragment);
        $this->assertStringContainsString('<p class="izvor-upozorenje">', $fragment);
        // Ogledalo: obična strana ima velike naslove „Odgovor" i „Izvori" kao do sada.
        $this->assertStringContainsString('<html', $strana);
        $this->assertStringContainsString('<header class="zaglavlje">', $strana);
        $this->assertStringContainsString('<h2>'.PrikazPomocnika::NASLOV_ODGOVORA.'</h2>', $strana);
        $this->assertStringContainsString(PrikazPomocnika::NASLOV_IZVORA, $strana);
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
                $this->assertStringContainsString(str_contains($tekstModela, '2027') ? 'Evo šta sam našao za tebe:' : $tekstModela, $tekst);
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

        $this->assertStringContainsString('<div class="poruka poruka-pomocnik"><p class="greska" role="alert">'.PrikazPomocnika::GRESKA_PRAZNO.'</p></div>', $prazno);
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

    // Skript: prozor se otvara iz dugmeta, Esc zatvara i vraća fokus, na telefonu fokus ostaje u prozoru, a obrazac se šalje istom putanjom;
    // kad ne uspe, pitanje se vraća u polje i šalje običnim putem. Bez spoljnih adresa.
    #[Test]
    public function skript_otvara_zatvara_vraca_fokus_i_salje_isti_obrazac(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        foreach ([
            "document.getElementById('pomocnik-prozor')", "document.querySelectorAll('[data-pomocnik-otvori]')", 'dogadjaj.preventDefault();', "dogadjaj.key === 'Escape' && jeOtvoren()",
            'meta.focus();', "document.querySelector('.plutajuci-dugme')].filter(vidljiv)[0];", "window.matchMedia('(max-width: 719px)')", "prozor.setAttribute('aria-modal', 'true');", 'e.inert = true;', "dogadjaj.key !== 'Tab'",
            'fetch(forma.action, {', "'X-Requested-With': 'XMLHttpRequest'", 'new FormData(forma)', "credentials: 'same-origin'", 'forma.submit();', "prozor.getAttribute('data-trazim')",
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $skript);
        $this->assertStringNotContainsString('eval(', $skript);
    }

    // Paket 67 T6: na početnoj je jedno dugme „Pitaj Vudar pomoćnika" na ekranu. Dok je dugme iz naslovnog dela vidljivo, plutajuće se ne vidi (skript samo menja klasu na html),
    // a kad se skroluje ispod, pojavi se. Dugme iz naslovnog dela postoji samo na početnoj, pa ostale strane ostaju iste; bez skripta oba ostaju.
    #[Test]
    public function na_pocetnoj_plutajuce_dugme_se_krije_dok_je_glavno_na_ekranu_a_ostale_strane_su_iste(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));
        $stil = $this->stilBezKomentara();

        foreach ([
            "document.querySelector('.hero-pitaj')", 'glavnoDugme.getBoundingClientRect()', "classList.toggle('pitaj-na-ekranu', okvir.bottom > 0 && okvir.top < window.innerHeight)",
            "window.addEventListener('scroll', osveziDugme, { passive: true });", "window.addEventListener('resize', osveziDugme);",
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
        $this->assertMatchesRegularExpression('/\n\.pitaj-na-ekranu \.plutajuci-dugme \{\s*display: none;\s*\}/', $stil);
        // Skript ne dira prozor: nigde se klasa „pitaj-na-ekranu" ne vezuje za zatvaranje.
        $this->assertSame(1, substr_count($skript, 'pitaj-na-ekranu'));

        // Dugme iz naslovnog dela je samo na početnoj, a klasu ne piše nijedan pogled (bez skripta oba dugmeta ostaju).
        $pocetna = $this->get(route('pocetna'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($pocetna, 'hero-pitaj'));
        $this->assertStringNotContainsString('pitaj-na-ekranu', $pocetna);
        foreach ([route('prilike.index'), route('vodici.index'), route('organizacije.index'), route('kalendar.index'), route('pomocnik.index')] as $adresa) {
            $this->assertStringNotContainsString('hero-pitaj', $this->get($adresa)->assertOk()->getContent(), $adresa);
        }
        $this->assertStringContainsString('class="plutajuci-dugme"', $this->get(route('prilike.index'))->getContent());
    }

    // Razgovor (paket 57 T9): Enter šalje, Shift+Enter pravi nov red (a dok se slovo sastavlja Enter ostaje običan); polje se prazni pre odgovora,
    // pitanje se odmah vidi kao poruka čoveka (tekst, ne HTML), odgovor stiže ispod, a kad ne uspe pitanje se vraća u polje i ide običnom formom.
    #[Test]
    public function skript_je_razgovor_enter_salje_shift_enter_ne_a_polje_se_prazni(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        foreach ([
            "polje.addEventListener('keydown', function (dogadjaj) {", "dogadjaj.key !== 'Enter' || dogadjaj.shiftKey || dogadjaj.isComposing", 'forma.requestSubmit();',
            "if (polje.value.trim() !== '') {", "polje.value = '';", 'var podaci = new FormData(forma);', "var moja = napraviPoruku('poruka-moja');", 'tekst.textContent = pitanje;',
            'stanje.remove();', "razgovor.insertAdjacentHTML('beforeend', html);", 'polje.value = pitanje;', 'forma.submit();', 'if (zauzet || pitanje.trim() === \'\') {',
            "polje.addEventListener('input', podesiVisinu);", 'Math.min(polje.scrollHeight, 140)',
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
        // Pitanje čoveka se nikad ne ubacuje kao HTML.
        $this->assertStringNotContainsString('moja.innerHTML', $skript);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $skript);
    }

    // Stil razgovora: poruke čoveka su u boji strane, poruke pomoćnika bele; izvori su male kartice; polje i dugme su u jednom redu; natpis polja vide samo čitači.
    #[Test]
    public function stil_slaze_razgovor_poruke_izvore_i_polje(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.poruka-moja \{[^}]*align-self: flex-end;[^}]*background: var\(--strana-pozadina\);[^}]*color: var\(--strana-slova\);[^}]*white-space: pre-wrap;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.poruka-pomocnik \{[^}]*align-self: flex-start;[^}]*background: var\(--card-bg\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.izvor-mala \{[^}]*padding: 10px 12px;[^}]*border-radius: 14px;[^}]*box-shadow: none;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.izvor-naslov \{[^}]*font-size: 15px;[^}]*font-weight: 600;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-forma \{[^}]*display: flex;[^}]*flex-direction: row;[^}]*align-items: flex-end;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-forma textarea \{[^}]*min-height: 46px;[^}]*resize: none;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.samo-citac \{[^}]*position: absolute;[^}]*width: 1px;[^}]*height: 1px;[^}]*clip-path: inset\(50%\);/', $stil);
        // Glava prozora je noć sa belim slovima i zlatnim fokusom (kao naslovni deo).
        $this->assertMatchesRegularExpression('/\n\.plutajuci-glava \{[^}]*var\(--noc\);[^}]*color: #fff;/s', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-glava :focus-visible \{\s*outline-color: var\(--zlato\);/', $stil);
    }

    // Stil: dugme je dole desno i ima najmanje 44 px; prozor na telefonu je preko celog ekrana, a na laptopu dole desno; skriven prozor se ne crta;
    // sadržaj ima razmak ispod da dugme ne pokrije kraj strane; u štampi nema dugmeta.
    #[Test]
    public function stil_postavlja_dugme_dole_desno_a_prozor_na_ceo_ekran_na_telefonu(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.plutajuci \{[^}]*position: fixed;[^}]*right: 16px;[^}]*bottom: 16px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-dugme \{[^}]*min-height: 48px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-prozor \{[^}]*position: fixed;[^}]*right: 16px;[^}]*bottom: 16px;[^}]*width: 480px;[^}]*max-height: 80vh;/', $stil);
        // Paket 64/T2: na laptopu 480 px široko i do 80 % ekrana (stara granica od 640 px je skinuta), polje za pitanje bar tri reda (108 px); telefon je kao ranije.
        preg_match('/\n\.plutajuci-prozor \{([^}]*)\}/', $stil, $prozor);
        $this->assertStringNotContainsString('640px', $prozor[1]);
        $this->assertMatchesRegularExpression('/@media \(min-width: 720px\) \{\s*\.plutajuci-forma textarea \{\s*min-height: 108px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-forma textarea \{[^}]*min-height: 46px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-prozor\[hidden\] \{\s*display: none;/', $stil);
        $this->assertMatchesRegularExpression('/@media \(max-width: 719px\) \{\s*\.plutajuci-prozor \{\s*inset: 0;[^}]*width: auto;[^}]*max-height: none;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.plutajuci-zatvori \{[^}]*min-height: 44px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.sadrzaj \{[^}]*padding-bottom: 96px;/', $stil);
        $this->assertMatchesRegularExpression('/\.plutajuci[,\s][^}]*display: none;/', substr($stil, (int) strpos($stil, '@media print')));
    }
}
