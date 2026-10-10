<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use App\Support\PrikazZaglavlja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

#[Group('baza')]
class JavnoZaglavljeTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    #[Test]
    public function zaglavlje_na_praznom_spisku_spisku_i_strani_prilike_nosi_ime_sajta_i_meni_prilike_vodici_organizacije_pomocnik(): void
    {
        $adrese = [route('prilike.index')];
        $this->assertStringContainsString('Trenutno nema otvorenih prilika.', $this->get($adrese[0])->getContent());

        $prilika = Prilika::factory()->objavljena()->create();
        $adrese[] = route('pocetna');
        $adrese[] = route('prilike.show', $prilika->slug);

        foreach ($adrese as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
            preg_match_all('#<a\b([^>]*)href="([^"]*)"[^>]*>(.*?)</a>#s', $zaglavlje[1] ?? '', $veze, PREG_SET_ORDER);
            $nazivi = array_map(fn (array $veza) => trim((string) preg_replace('/\s+/', ' ', strip_tags($veza[3]))), $veze);

            // Ime sajta (u dva reda, pa u dva dela) vodi na početnu, pa meni tim redom, svaka veza tačno jednom.
            $this->assertSame(['Putardo Vudar', 'Prilike', 'Vodiči', 'Organizacije', 'Pomoćnik'], $nazivi, $adresa);
            $this->assertSame([route('pocetna'), route('prilike.index'), route('vodici.index'), route('organizacije.index'), route('pomocnik.index')], array_map(fn (array $veza) => $veza[2], $veze), $adresa);
            $this->assertStringContainsString('class="logo"', $veze[0][1]);
            $this->assertStringContainsString('class="asistent"', $veze[4][1]);
        }
    }

    // Ogledalo: zaglavlje je ime sajta i tačno ovaj meni, bez drugih stavki. Do paketa 18 bio je jedan link (Prilike);
    // Ivanova odluka 10.10.2026.: meni Prilike, Vodiči, Pomoćnik, a Organizacije posle paketa 20.
    #[Test]
    public function zaglavlje_nema_drugih_linkova_osim_menija(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();
        preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
        preg_match_all('#<a\b[^>]*href="([^"]*)"#', $zaglavlje[1] ?? '', $adrese);

        $this->assertSame(
            [route('pocetna'), route('prilike.index'), route('vodici.index'), route('organizacije.index'), route('pomocnik.index')],
            $adrese[1],
        );
    }

    // Meni na telefonu: dugme postoji u kodu strane, ali je skriveno dok skript ne radi (tada ostaje običan spisak veza).
    #[Test]
    public function dugme_menija_je_skriveno_bez_skripta_i_vezano_za_meni(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();

        $this->assertMatchesRegularExpression('#<button class="dugme-meni" type="button" aria-label="'.preg_quote(PrikazZaglavlja::OTVORI_MENI, '#').'" aria-controls="meni" aria-expanded="false" hidden>#', $html);
        $this->assertStringContainsString('<div class="meni" id="meni">', $html);
        $this->assertMatchesRegularExpression('#<script src="[^"]*/js/javno\.js\?v=\d+"></script>#', $html);
        $this->assertFileExists(public_path('js/javno.js'));
    }

    // Ploča menija se zatvara na dugme, na vezu u njoj, na Escape i kad fokus ili dodir izađe iz zaglavlja (inače Tab posle
    // poslednje veze završi ispod ploče). Ponašanje je izmereno u pregledaču (izveštaj paketa 35), ovde je zaključano ono što ga pravi.
    #[Test]
    public function skript_menija_zatvara_plocu_na_dugme_vezu_escape_i_izlazak_iz_zaglavlja(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        $this->assertStringContainsString("dugme.addEventListener('click'", $skript);
        $this->assertStringContainsString("meni.addEventListener('click'", $skript);
        $this->assertStringContainsString("dogadjaj.key === 'Escape'", $skript);
        $this->assertStringContainsString("document.addEventListener('focusin', izvanZaglavlja);", $skript);
        $this->assertStringContainsString("document.addEventListener('click', izvanZaglavlja);", $skript);
        $this->assertStringContainsString('!zaglavlje.contains(dogadjaj.target)', $skript);
        $this->assertStringContainsString("dugme.setAttribute('aria-expanded'", $skript);
    }

    // Bez spoljnih adresa: stil, skript i slike dolaze sa našeg sajta; stil i skript ne zovu nijednu spoljnu adresu.
    #[Test]
    public function javne_strane_ne_zovu_spoljne_adrese(): void
    {
        $prilika = Prilika::factory()->objavljena()->create();
        $sopstveni = parse_url(route('pocetna'), PHP_URL_HOST);
        $proverenih = 0;

        foreach ([route('pocetna'), route('prilike.index'), route('prilike.show', $prilika->slug), route('vodici.index'), route('organizacije.index'), route('pomocnik.index')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();
            preg_match_all('#<(?:link|script|img)\b[^>]*\b(?:href|src)="([^"]+)"#', $html, $resursi);

            foreach ($resursi[1] as $resurs) {
                $proverenih++;
                $this->assertContains(parse_url($resurs, PHP_URL_HOST), [null, $sopstveni], $resurs);
            }
        }

        $this->assertGreaterThan(5, $proverenih);

        foreach (['css/javno.css', 'js/javno.js'] as $fajl) {
            $this->assertDoesNotMatchRegularExpression('#(?:https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', (string) preg_replace('#/\*.*?\*/|^\s*//[^\n]*#sm', '', (string) file_get_contents(public_path($fajl))), $fajl);
        }
    }

    // Boje, zaobljenje i senka su iz prototipa (promenljive na početku njegovog app.css), pod prototipskim nazivima; stari nazivi ne postoje.
    #[Test]
    public function stil_nosi_prototipske_boje_pod_prototipskim_nazivima(): void
    {
        $stil = $this->stilBezUpita();
        $prototip = [
            'page' => '#eef5f1', 'soft' => '#f4f6f3', 'soft-2' => '#e7eee9', 'text' => '#071012', 'muted' => '#59635f', 'dark' => '#071012',
            'blue' => '#4d98ee', 'blue-hover' => '#347ed3', 'green' => '#55d8b2', 'green-hover' => '#3fc49e', 'red' => '#ff4a4a', 'red-hover' => '#e73939',
            'white' => '#ffffff', 'border' => 'rgba(7, 16, 18, 0.12)', 'surface-border' => 'rgba(7, 16, 18, 0.08)', 'surface-border-strong' => 'rgba(7, 16, 18, 0.12)',
            'shadow' => '0 12px 28px rgba(7, 16, 18, 0.08)', 'radius' => '8px', 'header-bg' => 'rgba(238, 245, 241, 0.94)', 'card-bg' => '#f4f6f3',
            'input-bg' => '#ffffff', 'input-text' => '#071012', 'button-dark-bg' => '#071012', 'button-dark-text' => '#ffffff',
        ];

        foreach ($prototip as $naziv => $vrednost) {
            $this->assertStringContainsString('--'.$naziv.': '.$vrednost.';', $stil, $naziv);
        }

        // Ogledalo: nazivi koji su pre prototipa postojali u našem stilu više se ne koriste.
        $this->assertDoesNotMatchRegularExpression('/--(?:strana|kartica|tekst|prigusen|granica|plava|zelena|senka|polumer)\b/', $stil);
        $this->assertSame(24, count($prototip));
    }

    #[Test]
    public function stil_je_obican_css_fajl_koji_postoji_a_ne_build(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();

        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*/css/javno\.css\?v=\d+">#', $html);
        $this->assertFileExists(public_path('css/javno.css'));
        $this->assertStringNotContainsString('/build/', $html);
    }
}
