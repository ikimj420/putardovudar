<?php

namespace Tests\Feature\Baza;

use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.', 'Drugi korak.']]);
        $organizacija = Organizacija::factory()->objavljena()->create(['telefon' => '011/123-456', 'sajt' => 'https://primer.rs/', 'usluge' => ['Savetovanje']]);

        $sablon = Sablon::factory()->objavljen()->create();

        return [
            'šablon' => $this->post(route('sabloni.sklopi', $sablon->slug), ['ime' => 'Ana'])->assertOk()->getContent(),
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

    // Meni, putanja i dugmad se ne štampaju; svaki od tih selektora pogađa element koji na ove tri strane stvarno postoji (inače je pravilo mrtvo).
    #[Test]
    public function meni_putanja_i_dugmad_se_ne_stampaju_a_pravila_pogadjaju_postojece_elemente(): void
    {
        $sakriveno = $this->selektoriSa($this->stampa(), 'display: none');
        $stranice = $this->strane();

        $this->assertSame(['.zaglavlje', '.mrvice', '.deljenje', '.dugme-red', '.dugme-veza', '.obrazac', '.uputstvo'], $sakriveno);

        foreach ($sakriveno as $selektor) {
            $klasa = ltrim($selektor, '.');
            $naStranama = array_filter($stranice, fn (string $html) => preg_match('/class="[^"]*\b'.preg_quote($klasa, '/').'\b/', $html) === 1);

            $this->assertNotEmpty($naStranama, $selektor.' ne pogađa nijedan element na stranama prilike, vodiča, organizacije i šablona');
        }
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
        // Broj je beo na plavoj podlozi u običnom stilu; u štampi bez boje bio bi nevidljiv, pa mora da bude crn.
        $this->assertMatchesRegularExpression('/\.koraci li::before \{[^}]*background: var\(--blue-text\);[^}]*color: #fff;/', $stil);

        $this->assertSame(2, preg_match_all('#<li>#', explode('<ol class="koraci">', $this->strane()['vodič'])[1] ?? ''));
    }
}
