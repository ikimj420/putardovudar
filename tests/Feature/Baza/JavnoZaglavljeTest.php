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
    public function zaglavlje_na_praznom_spisku_spisku_i_strani_prilike_nosi_ime_sajta_i_meni_prilike_vodici_organizacije_kalendar_sabloni_pomocnik(): void
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
            $this->assertSame(['Putardo Vudar', 'Prilike', 'Vodiči', 'Organizacije', 'Kalendar', 'Pomoćnik'], $nazivi, $adresa);
            $this->assertSame([route('pocetna'), route('prilike.index'), route('vodici.index'), route('organizacije.index'), route('kalendar.index'), route('pomocnik.index')], array_map(fn (array $veza) => $veza[2], $veze), $adresa);
            $this->assertStringContainsString('class="logo"', $veze[0][1]);
            $this->assertStringContainsString('class="asistent"', $veze[5][1]);
        }
    }

    // Ogledalo: zaglavlje je ime sajta i tačno ovaj meni, bez drugih stavki. Do paketa 18 bio je jedan link (Prilike);
    // Ivanova odluka 10.10.2026.: meni Prilike, Vodiči, Pomoćnik, a Organizacije posle paketa 20, Kalendar posle paketa 44; Šabloni (paket 51) su od paketa 56 samo u adminu.
    #[Test]
    public function zaglavlje_nema_drugih_linkova_osim_menija(): void
    {
        $html = $this->get(route('prilike.index'))->getContent();
        preg_match('#<header class="zaglavlje">(.*?)</header>#s', $html, $zaglavlje);
        preg_match_all('#<a\b[^>]*href="([^"]*)"#', $zaglavlje[1] ?? '', $adrese);

        $this->assertSame(
            [route('pocetna'), route('prilike.index'), route('vodici.index'), route('organizacije.index'), route('kalendar.index'), route('pomocnik.index')],
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

    // Boje, zaobljenje i senka su iz odobrenog predloga (Dokumentacija/izgled-predlog.html, paket 57); stari prototipski nazivi plave, zelene i crvene ne postoje.
    #[Test]
    public function stil_nosi_boje_i_oblik_odobrenog_predloga(): void
    {
        $stil = $this->stilBezUpita();
        $predlog = [
            'noc' => '#270f43', 'noc-duboka' => '#1b0a30', 'ljubicasta' => '#651fc7', 'lila' => '#a85ff2', 'narandzasta' => '#e07229', 'narandzasta-meka' => '#f0994c',
            'zlato' => '#e7b53c', 'bobica' => '#891f5d', 'bobica-svetla' => '#b83c7e', 'papir' => '#fbfafd', 'pesak' => '#f2f0f6', 'mastilo' => '#1a1a1c',
            'page' => '#fbfafd', 'soft' => '#f2f0f6', 'text' => '#1a1a1c', 'dark' => '#270f43', 'card-bg' => '#ffffff', 'input-bg' => '#ffffff',
            'button-dark-bg' => '#270f43', 'button-dark-text' => '#ffffff', 'akcent' => '#651fc7', 'fokus' => '#651fc7', 'radius' => '22px', 'radius-polje' => '14px',
        ];

        foreach ($predlog as $naziv => $vrednost) {
            $this->assertStringContainsString('--'.$naziv.': '.$vrednost.';', $stil, $naziv);
        }
        $this->assertSame(24, count($predlog));

        // Ogledalo: nazivi iz prototipa (plava, zelena, crvena) i nazivi pre prototipa ne postoje.
        $this->assertDoesNotMatchRegularExpression('/--(?:blue|green|red)[a-z-]*:|var\(--(?:blue|green|red)[a-z-]*\)/', $stil);
        $this->assertDoesNotMatchRegularExpression('/--(?:strana|kartica|tekst|prigusen|granica|plava|zelena|senka|polumer)\b/', $stil);
    }

    // Bez plave, zelene i crvene: nijedna boja u stilu nema ton plave, zelene, tirkizne ni crvene. Ljubičasta, ruža, narandžasta i zlato su dozvoljeni;
    // sive i skoro bele (papir) nemaju ton.
    #[Test]
    public function nijedna_boja_u_stilu_nije_plava_zelena_ni_crvena(): void
    {
        $boje = $this->bojeIzStila($this->stilBezKomentara());

        // Merilo mora da obiđe skup: stil ima više od dvadeset boja, a među njima i one sa tonom.
        $this->assertGreaterThan(25, count($boje));
        $saTonom = array_filter($boje, fn (array $hsl) => $hsl[1] > 0.25);
        $this->assertGreaterThan(10, count($saTonom));

        foreach ($boje as $naziv => [$ton, $zasicenje]) {
            $this->assertFalse($this->jePlavaZelenaIliCrvena($ton, $zasicenje), $naziv.' ima ton '.round($ton).'°');
        }

        // Ogledalo: merilo vidi prototipske boje (plava, zelena, crvena) i ne vidi ljubičastu, ružu, narandžastu, zlato ni sivu.
        foreach (['#4d98ee' => true, '#55d8b2' => true, '#ff4a4a' => true, '#2563b8' => true, '#651fc7' => false, '#891f5d' => false, '#e07229' => false, '#e7b53c' => false, '#5d5866' => false, '#fbfafd' => false] as $boja => $ocekivano) {
            [$ton, $zasicenje] = $this->tonISasicenje($boja);

            $this->assertSame($ocekivano, $this->jePlavaZelenaIliCrvena($ton, $zasicenje), $boja);
        }
    }

    private function jePlavaZelenaIliCrvena(float $ton, float $zasicenje): bool
    {
        return $zasicenje > 0.25 && ($ton >= 75 && $ton < 255 || $ton >= 345 || $ton < 15);
    }

    /** @return array<string, array{0: float, 1: float}> boja kako je u stilu → [ton u stepenima, zasićenje] */
    private function bojeIzStila(string $stil): array
    {
        $boje = [];

        preg_match_all('/#(?:[0-9a-f]{6}|[0-9a-f]{3})\b/i', $stil, $hex);
        foreach ($hex[0] as $boja) {
            $boje[strtolower($boja)] = $this->tonISasicenje($boja);
        }

        preg_match_all('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $stil, $rgb, PREG_SET_ORDER);
        foreach ($rgb as [$ceo, $r, $g, $b]) {
            $boje[$ceo] = $this->tonISasicenje(sprintf('#%02x%02x%02x', $r, $g, $b));
        }

        return $boje;
    }

    /** @return array{0: float, 1: float} */
    private function tonISasicenje(string $boja): array
    {
        $boja = ltrim($boja, '#');
        $boja = strlen($boja) === 3 ? $boja[0].$boja[0].$boja[1].$boja[1].$boja[2].$boja[2] : $boja;
        [$r, $g, $b] = array_map(fn (string $c) => hexdec($c) / 255.0, str_split($boja, 2));
        $najvise = max($r, $g, $b);
        $najmanje = min($r, $g, $b);
        $razlika = $najvise - $najmanje;
        $svetlost = ($najvise + $najmanje) / 2;
        $zasicenje = $razlika === 0.0 ? 0.0 : $razlika / (1 - abs(2 * $svetlost - 1));

        $ton = match (true) {
            $razlika === 0.0 => 0.0,
            $najvise === $r => 60 * fmod(($g - $b) / $razlika, 6),
            $najvise === $g => 60 * (($b - $r) / $razlika + 2),
            default => 60 * (($r - $g) / $razlika + 4),
        };

        return [$ton < 0 ? $ton + 360 : $ton, $zasicenje];
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
