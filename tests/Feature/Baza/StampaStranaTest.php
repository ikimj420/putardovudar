<?php

namespace Tests\Feature\Baza;

use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 50: strana prilike, vodiča i organizacije se lepo štampa; paket 51 dodaje stranu šablona (štampa se samo sklopljen tekst). Izgled u štampi se meri u pregledaču (PDF u Dokumentacija/slike/);
// ovde se čuva ono što se ne vidi iz PDF-a: da pravila postoje, da pogađaju elemente koji stvarno postoje i da ne sakrivaju sadržaj.
#[Group('baza')]
class StampaStranaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    /** @return array<string, string> tip strane => HTML */
    private function strane(): array
    {
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));

        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-12-15', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/konkurs']);
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.', 'Drugi korak.'], 'primer' => 'Primer.']);
        $organizacija = Organizacija::factory()->objavljena()->create(['telefon' => '011/123-456', 'sajt' => 'https://primer.rs/', 'usluge' => ['Savetovanje']]);

        // Model ne radi: napisan tekst je sklopljen šablon, što je dovoljno za štampu (ista klasa za tekst i napomenu).
        Http::fake(['*' => Http::response('', 500)]);
        $sablon = Sablon::factory()->objavljen()->create();

        return [
            'šablon' => $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent(),
            'napisan tekst' => $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['ime' => 'Ana']])->assertOk()->getContent(),
            'prilika' => $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent(),
            'vodič' => $this->get(route('vodici.show', $vodic->slug))->assertOk()->getContent(),
            'organizacija' => $this->get(route('organizacije.show', $organizacija->slug))->assertOk()->getContent(),
        ];
    }

    private function stampa(): string
    {
        $stil = $this->stilBezKomentara();
        $pocetak = strpos($stil, '@media print {');

        $this->assertNotFalse($pocetak, 'u javno.css nema pravila za štampu');

        for ($dubina = 0, $i = (int) strpos($stil, '{', (int) $pocetak); $i < strlen($stil); $i++) {
            $dubina += $stil[$i] === '{' ? 1 : ($stil[$i] === '}' ? -1 : 0);

            if ($dubina === 0) {
                return substr($stil, (int) $pocetak, $i - (int) $pocetak + 1);
            }
        }

        $this->fail('blok za štampu nije zatvoren');
    }

    /** @return list<string> selektori pravila sa datim svojstvom u bloku za štampu */
    private function selektoriSa(string $stampa, string $svojstvo): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $stampa, $pravila, PREG_SET_ORDER);
        $nadjeno = [];

        foreach ($pravila as [, $selektori, $telo]) {
            if (str_contains($telo, $svojstvo)) {
                array_push($nadjeno, ...array_map('trim', explode(',', $selektori)));
            }
        }

        return $nadjeno;
    }

    // Meni, putanja i dugmad se ne štampaju; svaki od tih selektora pogađa element koji na ove strane stvarno postoji (inače je pravilo mrtvo).
    #[Test]
    public function meni_putanja_i_dugmad_se_ne_stampaju_a_pravila_pogadjaju_postojece_elemente(): void
    {
        $sakriveno = $this->selektoriSa($this->stampa(), 'display: none');
        $stranice = $this->strane();

        $this->assertSame(['.zaglavlje', '.linija', '.mrvice', '.deljenje', '.dugme-red', '.dugme-veza', '.obrazac', '.uputstvo', '.plutajuci', '.dugme-kopiraj'], $sakriveno);

        foreach ($sakriveno as $selektor) {
            $klasa = ltrim($selektor, '.');
            $naStranama = array_filter($stranice, fn (string $html) => preg_match('/class="[^"]*\b'.preg_quote($klasa, '/').'\b/', $html) === 1);

            $this->assertNotEmpty($naStranama, $selektor.' ne pogađa nijedan element na stranama prilike, vodiča, organizacije i šablona');
        }
    }

    // Paket 56: kad prozor pomoćnika pokazuje napisan tekst, štampa se samo taj tekst. Pravila drugog bloka važe samo uz taj uslov,
    // da otvoren prozor sa pitanjima ne promeni štampu strane iza njega (ogledalo je prvi blok, gde se `main` ne sakriva).
    #[Test]
    public function otvoren_prozor_sa_napisanim_tekstom_stampa_samo_tekst_i_samo_tada(): void
    {
        $stil = $this->stilBezKomentara();
        $prvi = strpos($stil, '@media print {');
        $drugi = strpos($stil, '@media print {', (int) $prvi + 1);

        $this->assertNotFalse($drugi, 'u javno.css nema drugog bloka za štampu');
        $blok = substr($stil, (int) $drugi);
        $uslov = 'html.pomocnik-otvoren:has(.plutajuci-rezultat .sklopljen-tekst)';

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', substr($blok, strlen('@media print {')), $pravila, PREG_SET_ORDER);
        $selektori = [];
        foreach ($pravila as [, $izbor, $telo]) {
            foreach (array_map('trim', explode(',', $izbor)) as $selektor) {
                $selektori[] = $selektor;
                $this->assertStringStartsWith($uslov.' ', $selektor, 'pravilo bez uslova menja štampu svake strane');
            }
        }

        $skriveno = [];
        foreach ($pravila as [, $izbor, $telo]) {
            if (str_contains($telo, 'display: none')) {
                array_push($skriveno, ...array_map(fn (string $selektor) => substr(trim($selektor), strlen($uslov) + 1), explode(',', $izbor)));
            }
        }
        // Od paketa 57 prozor je razgovor: štampa se samo poruka sa napisanim tekstom, pa se sakrivaju i sve ostale poruke.
        $this->assertSame(['.zaglavlje', 'main', '.plutajuci-glava', '.plutajuci-forma', '.plutajuci-uvod', '.plutajuci-sablon', '.sklopljen h2', '.poruka:not(:has(.sklopljen-tekst))'], $skriveno);
        $this->assertContains($uslov.' .plutajuci', $selektori);
        $this->assertMatchesRegularExpression('/\.plutajuci,[^{]*\.plutajuci-prozor \{[^}]*position: static;[^}]*display: block;/', $blok);
        $this->assertMatchesRegularExpression('/\.plutajuci-telo \{[^}]*overflow: visible;/', $blok);
        $this->assertMatchesRegularExpression('/\) \.poruka \{[^}]*max-width: none;[^}]*border: 0;[^}]*box-shadow: none;/', $blok);
    }

    // Ogledalo: sadržaj strane se ne sakriva u štampi.
    #[Test]
    public function sadrzaj_strane_se_ne_sakriva_u_stampi(): void
    {
        $sakriveno = $this->selektoriSa($this->stampa(), 'display: none');

        foreach (['main', 'h1', '.sadrzaj', '.sadrzaj-opis', '.ploca', '.izvor', '.izvor-upozorenje', '.koraci', '.koraci li', '.usluge', '.oznake', '.naslovna-traka'] as $sadrzaj) {
            $this->assertNotContains($sadrzaj, $sakriveno, $sadrzaj);
        }
    }

    // Veze ka spoljnim stranama se ispisuju kao adresa; na ovim stranama takve veze postoje u glavnom delu (merilo nije prazno).
    #[Test]
    public function spoljne_veze_se_ispisuju_kao_adresa(): void
    {
        $this->assertStringContainsString('main a[href^="http"]::after {', $this->stampa());
        $this->assertMatchesRegularExpression('/main a\[href\^="http"\]::after \{[^}]*content: " \(" attr\(href\) "\)";/', $this->stampa());
        $crno = $this->selektoriSa($this->stampa(), 'color: #000');
        $this->assertContains('a', $crno);
        $this->assertContains('.izvor a', $crno, 'veza zvaničnog izvora je crna, ne plava');

        $stranice = $this->strane();
        foreach (['prilika', 'organizacija'] as $tip) {
            preg_match('#<main.*</main>#s', $stranice[$tip], $glavni);
            $this->assertMatchesRegularExpression('#<a [^>]*href="https://primer\.rs/#', $glavni[0] ?? '', $tip);
        }
    }

    // Koraci vodiča ostaju numerisani: brojač postoji u običnom stilu, a u štampi broj ima okvir i crnu boju (pozadine se ne štampaju).
    #[Test]
    public function koraci_vodica_ostaju_numerisani_i_bez_pozadine(): void
    {
        $stil = $this->stilBezKomentara();
        $stampa = $this->stampa();

        $this->assertMatchesRegularExpression('/\.koraci li::before \{[^}]*counter-increment: koraci;[^}]*content: counter\(koraci\);/', $stil);
        $this->assertMatchesRegularExpression('/\.koraci li::before \{[^}]*border: 1px solid #000;[^}]*color: #000;/', $stampa);
        $this->assertDoesNotMatchRegularExpression('/\.koraci li::before \{[^}]*(?:display: none|content: none)/', $stampa);
        // Broj je beo na ljubičastoj podlozi u običnom stilu; u štampi bez boje bio bi nevidljiv, pa mora da bude crn.
        $this->assertMatchesRegularExpression('/\.koraci li::before \{[^}]*background: var\(--akcent\);[^}]*color: #fff;/', $stil);

        $this->assertSame(2, preg_match_all('#<li>#', explode('<ol class="koraci">', $this->strane()['vodič'])[1] ?? ''));
    }
}
