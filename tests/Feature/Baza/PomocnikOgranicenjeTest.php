<?php

namespace Tests\Feature\Baza;

use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 22: broj pitanja po adresi. Preko granice posetilac vidi našu stranu sa porukom (429), a model se ne zove.
#[Group('baza')]
class PomocnikOgranicenjeTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
        // Nema objavljenih prilika, pa je svako pitanje tačno jedan poziv modela (formular), a odgovor je „Nemam podatak.".
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => json_encode(['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []])]])]);
    }

    private function pitaj(string $pitanje = 'Ima li posla?'): TestResponse
    {
        return $this->post(route('pomocnik.pitaj'), ['pitanje' => $pitanje]);
    }

    #[Test]
    public function peto_pitanje_u_minuti_prolazi_a_sesto_dobija_poruku_na_strani_i_ne_stize_do_modela(): void
    {
        foreach (range(1, 5) as $broj) {
            $this->pitaj()->assertOk();
        }
        Http::assertSentCount(5);

        $odgovor = $this->pitaj('Ima li prakse u Kragujevcu?')->assertStatus(429);
        $html = $odgovor->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString(PrikazPomocnika::PREVISE_PITANJA, $tekst);
        $this->assertStringContainsString('class="greska"', $html);
        $this->assertStringContainsString(PrikazPomocnika::NASLOV, $tekst);
        $this->assertStringContainsString('name="pitanje"', $html);
        // Pitanje stoji u polju (ne samo u primeru uz polje), da posetilac ne mora da ga piše ponovo.
        $this->assertStringContainsString('>Ima li prakse u Kragujevcu?</textarea>', $html);
        $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $tekst);
        $this->assertStringNotContainsString('Too Many Attempts', $html);
        $this->assertStringNotContainsString('Nemam podatak.', $tekst);
        Http::assertSentCount(5);

        $sekundi = (int) $odgovor->headers->get('Retry-After');
        $this->assertGreaterThan(0, $sekundi);
        $this->assertLessThanOrEqual(60, $sekundi);
    }

    // Ogledalo: druga adresa nije ograničena zbog prve.
    #[Test]
    public function ogranicenje_je_po_adresi(): void
    {
        foreach (range(1, 6) as $broj) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->pitaj();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->pitaj()->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->pitaj()->assertOk();
    }

    #[Test]
    public function posle_minuta_pitanje_ponovo_prolazi(): void
    {
        foreach (range(1, 5) as $broj) {
            $this->pitaj()->assertOk();
        }
        $this->pitaj()->assertStatus(429);

        $this->travel(61)->seconds();

        $this->pitaj()->assertOk();
    }

    #[Test]
    public function broj_pitanja_dolazi_iz_podesavanja(): void
    {
        config()->set('pomocnik.pitanja_u_minuti', 2);

        $this->pitaj()->assertOk();
        $this->pitaj()->assertOk();
        $this->pitaj()->assertStatus(429);
    }

    // Ogledalo: ograničenje važi za slanje pitanja, a ne za čitanje strane.
    #[Test]
    public function otvaranje_strane_nije_ograničeno(): void
    {
        foreach (range(1, 12) as $broj) {
            $this->get(route('pomocnik.index'))->assertOk();
        }
    }

    // Ogledalo: pitanje koje ne prođe proveru (prazno) takođe troši granicu, jer ograničenje stoji pre provere.
    #[Test]
    public function prazna_pitanja_troše_granicu_kao_i_ostala(): void
    {
        foreach (range(1, 5) as $broj) {
            $this->pitaj('')->assertStatus(422);
        }

        $this->pitaj()->assertStatus(429);
    }
}
