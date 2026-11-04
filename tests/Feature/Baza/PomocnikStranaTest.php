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
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;
use Tests\Podrska\LazniClaude;

#[Group('baza')]
class PomocnikStranaTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const MODEL = LazniClaude::ADRESA;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 9, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function prilika(): Prilika
    {
        return Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
    }

    /** Formular (format json) uvek radi; drugi poziv vraća tekst odgovora ili baca grešku. */
    private function model(string|callable $odgovor, bool $formularRadi = true): void
    {
        Http::fake([self::MODEL => function (Request $zahtev) use ($odgovor, $formularRadi) {
            if (LazniClaude::jeJson($zahtev)) {
                return $formularRadi
                    ? LazniClaude::odgovor(json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]))
                    : throw new ConnectionException('nema veze');
            }

            return is_callable($odgovor) ? $odgovor() : LazniClaude::odgovor($odgovor);
        }]);
    }

    private function pitaj(string $pitanje = 'Ima li posla u Nišu?'): TestResponse
    {
        return $this->post(route('pomocnik.pitaj'), ['pitanje' => $pitanje]);
    }

    #[Test]
    public function strana_se_otvara_sa_poljem_za_pitanje_i_dugmetom(): void
    {
        $html = $this->get(route('pomocnik.index'))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString(PrikazPomocnika::NASLOV, $tekst);
        $this->assertStringContainsString(PrikazPomocnika::UVOD, $tekst);
        $this->assertStringContainsString(PrikazPomocnika::OZNAKA_POLJA, $tekst);
        $this->assertStringContainsString(PrikazPomocnika::DUGME, $tekst);
        $this->assertStringContainsString('name="pitanje"', $html);
        $this->assertStringContainsString('action="'.route('pomocnik.pitaj').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $tekst);
        Http::assertNothingSent();
    }

    // Od paketa 57 (Ivan, 10.10.2026.) u zaglavlju nema linka ka pomoćniku: pomoćnik je samo dugme dole desno (obična veza ka strani pomoćnika, a skript ga
    // pretvara u prozor); na samoj strani pomoćnika u telu nema nijedne veze ka njoj.
    #[Test]
    public function link_ka_pomocniku_nije_u_zaglavlju_a_u_telu_je_samo_plutajuce_dugme(): void
    {
        $prilika = $this->prilika();
        $adresaPomocnika = route('pomocnik.index');

        foreach ([route('pocetna'), route('prilike.index'), route('prilike.show', $prilika->slug), $adresaPomocnika] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();
            preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
            $telo = (string) preg_replace('#<header class="zaglavlje">.*?</header>#s', '', $html);
            $telo = str_replace('action="'.route('pomocnik.pitaj').'"', '', $telo);
            $naStraniPomocnika = $adresa === $adresaPomocnika;

            $this->assertStringNotContainsString($adresaPomocnika, $zaglavlje[1] ?? '', $adresa);
            $this->assertStringNotContainsString(PrikazPomocnika::NASLOV, $zaglavlje[1] ?? '', $adresa);
            // Na početnoj je u telu i dugme naslovnog dela (paket 57) koje otvara isti prozor; ostalo je samo plutajuće dugme.
            $this->assertSame($naStraniPomocnika ? 0 : ($adresa === route('pocetna') ? 2 : 1), substr_count($telo, 'href="'.$adresaPomocnika.'"'), $adresa);
            $this->assertSame($naStraniPomocnika ? 0 : 1, substr_count($telo, '<a class="plutajuci-dugme" href="'.$adresaPomocnika.'"'), $adresa);
        }
    }

    // Ime (Ivan, 10.10.2026.): dugme je „Pitaj Vudar pomoćnika", a naslov prozora i strane „Vudar pomoćnik" (kao na starom sajtu); doslovno, ne preko konstante.
    #[Test]
    public function pomocnik_se_zove_vudar_pomocnik_a_dugme_pitaj_vudar_pomocnika(): void
    {
        $this->assertSame('Vudar pomoćnik', PrikazPomocnika::NASLOV);
        $this->assertSame('Pitaj Vudar pomoćnika', PrikazPomocnika::PITAJ_VUDAR);

        $strana = $this->get(route('pomocnik.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<h1>Vudar pomoćnik</h1>', $strana);
        $this->assertStringContainsString('<title>Vudar pomoćnik - Putardo Vudar</title>', $strana);

        $prilika = $this->get(route('prilike.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<h2 id="pomocnik-naslov">Vudar pomoćnik</h2>', $prilika);
        $this->assertStringContainsString('</svg></span>Pitaj Vudar pomoćnika</a>', $prilika);
        $this->assertStringContainsString('data-pomocnik-otvori aria-controls="pomocnik-prozor">Pitaj Vudar pomoćnika</a>', $this->get(route('pocetna'))->getContent());
    }

    #[Test]
    public function odgovor_i_izvori_su_kartice_a_izvore_sklapa_kod(): void
    {
        $prilika = $this->prilika();
        $this->model('Postoji posao radnika u skladištu, prijave su do 15. novembra 2026.');

        $odgovor = $this->pitaj()->assertOk();
        $html = $odgovor->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Postoji posao radnika u skladištu, prijave su do 15. novembra 2026.', $tekst);
        $this->assertStringContainsString(PrikazPomocnika::NASLOV_IZVORA, $tekst);
        $this->assertStringContainsString('<a class="kartica-veza" href="'.route('prilike.show', $prilika->slug).'">Radnik u skladištu</a>', $html);
        $this->assertStringContainsString('Rok: 15.11.2026.', $tekst);
        $this->assertStringContainsString('<a href="https://primer.rs/skladiste-nis" rel="noopener noreferrer nofollow" target="_blank">Probni izvor</a>', $html);
        $this->assertSame(1, substr_count($html, '<article class="kartica">'));
        $this->assertStringContainsString('>Ima li posla u Nišu?</textarea>', $html);
    }

    #[Test]
    public function izmisljen_datum_u_odgovoru_modela_se_na_strani_menja_sablonom(): void
    {
        $this->prilika();
        $this->model('Prijave su do 20. oktobra 2026.');

        $tekst = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        $this->assertStringNotContainsString('20. oktobra', $tekst);
        $this->assertStringContainsString(Pomocnik::SABLON_NASLOV.' • Radnik u skladištu — Rok: 15.11.2026.', $tekst);
    }

    #[Test]
    public function nema_zapisa_pise_nemam_podatak_bez_izvora(): void
    {
        $this->model('Ovo ne sme da se pročita.');

        $odgovor = $this->pitaj()->assertOk();
        $tekst = $this->vidljivTekst($odgovor->getContent());

        $this->assertStringContainsString(Pomocnik::NEMAM_PODATAK, $tekst);
        $this->assertStringNotContainsString(PrikazPomocnika::NASLOV_IZVORA, $tekst);
        $this->assertStringNotContainsString('Ovo ne sme', $tekst);
    }

    #[Test]
    public function kad_ollama_ne_radi_strana_radi_i_kaze_da_pomocnik_ne_radi(): void
    {
        $this->prilika();
        $this->model('x', formularRadi: false);

        $tekst = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $tekst);
        $this->assertStringContainsString(PrikazPomocnika::OZNAKA_POLJA, $tekst);
        $this->assertStringNotContainsString(PrikazPomocnika::NASLOV_IZVORA, $tekst);
    }

    // Ogledalo: ostale strane rade kad Ollama ne radi, a bez greške pomoćnik ne pominje da ne radi.
    #[Test]
    public function ostale_strane_rade_i_kad_ollama_ne_radi(): void
    {
        $prilika = $this->prilika();
        $this->model('x', formularRadi: false);

        foreach ([route('pocetna'), route('prilike.index'), route('prilike.show', $prilika->slug), route('pomocnik.index')] as $adresa) {
            $tekst = $this->vidljivTekst($this->get($adresa)->assertOk()->getContent());

            $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $tekst, $adresa);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function prazno_i_predugo_pitanje_se_odbija_bez_poziva_modela(): void
    {
        $this->model('x');

        foreach (['', '   '] as $prazno) {
            $this->assertStringContainsString(PrikazPomocnika::GRESKA_PRAZNO, $this->pitaj($prazno)->assertStatus(422)->getContent());
        }

        $this->assertStringContainsString(PrikazPomocnika::GRESKA_DUGACKO, $this->pitaj(str_repeat('a', Pomocnik::NAJVISE_ZNAKOVA_PITANJA + 1))->assertStatus(422)->getContent());
        $this->post(route('pomocnik.pitaj'))->assertStatus(422);
        Http::assertNothingSent();
    }

    // Ogledalo: pitanje od tačno 300 znakova se prima.
    #[Test]
    public function pitanje_na_granici_se_prima(): void
    {
        $this->model('x');

        $this->pitaj(str_repeat('a', Pomocnik::NAJVISE_ZNAKOVA_PITANJA))->assertOk();
    }

    #[Test]
    public function tekst_odgovora_i_pitanja_se_ispisuje_bez_html_a(): void
    {
        $this->prilika();
        $this->model('<script>alert(1)</script> Posao postoji.');

        $html = $this->pitaj('<b>Ima li posla u Nišu?</b>')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Ima li posla', $html);
        $this->assertStringContainsString('&lt;b&gt;Ima li posla', $html);
    }

    // Pitanje ide POST-om; adresa sa ?pitanje= ne pokreće pomoćnika, pa pitanje ne završava u adresi.
    #[Test]
    public function pitanje_u_adresi_ne_pokrece_pomocnika(): void
    {
        $this->model('x');

        $html = $this->get(route('pomocnik.index', ['pitanje' => 'Ima li posla u Čačku?']))->assertOk()->getContent();

        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringNotContainsString('Ima li posla u Čačku?', $html);
        Http::assertNothingSent();
    }
}
