<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 67/T11: u „Napiši moj" tekst modela prolazi samo ako upisano ime stoji u njemu isto; inače ide sklopljen šablon (primer iz probe: „Marka Marković").
#[Group('baza')]
class ImeKaoUpisanoUNapisiMojTest extends BazaTestCase
{
    use RefreshDatabase;

    private const MODEL = LazniClaude::ADRESA;

    private string $odgovorModela = '';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([self::MODEL => fn () => LazniClaude::odgovor($this->odgovorModela)]);
    }

    private function sablon(bool $saImenom = true): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => $saImenom
                ? [['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]]
                : [['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => $saImenom ? "Poštovani,\n\n{tekst}\n\nS poštovanjem,\n{ime}" : "Poštovani,\n\n{tekst}",
        ]);
    }

    /** @param  array<string, string>  $polja */
    private function napisi(Sablon $sablon, array $polja): string
    {
        return $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => $polja, 'rod' => 'muski'])->assertOk()->getContent();
    }

    private function napisan(string $html): string
    {
        preg_match('#<div class="sklopljen-tekst" id="napisan-tekst">(.*?)</div>#s', $html, $nalaz);

        return html_entity_decode($nalaz[1] ?? '');
    }

    #[Test]
    public function model_koji_promeni_ime_dobija_sklopljen_sablon_sa_imenom_kako_je_upisano(): void
    {
        $this->odgovorModela = "Poštovani,\n\nPraksa dve nedelje.\n\nS poštovanjem,\nMarka Marković";

        $html = $this->napisi($this->sablon(), ['ime' => 'Marko Marković', 'tekst' => 'Praksa dve nedelje.']);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertSame("Poštovani,\n\nPraksa dve nedelje.\n\nS poštovanjem,\nMarko Marković", $this->napisan($html));
    }

    // Ogledalo: isti tekst sa imenom kako je upisano (a upisano drugim slovima i sa viškom razmaka) prolazi i prikazuje se.
    #[Test]
    public function ime_kako_je_upisano_prolazi_bez_obzira_na_velika_slova_i_visak_razmaka(): void
    {
        $this->odgovorModela = "Poštovani,\n\nPraksa dve nedelje.\n\nS poštovanjem,\nMarko Marković";

        $html = $this->napisi($this->sablon(), ['ime' => '  marko   MARKOVIĆ ', 'tekst' => 'Praksa dve nedelje.']);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        $this->assertSame($this->odgovorModela, $this->napisan($html));
    }

    // Šablon bez polja „ime" radi kao pre: nijedno ime se ne traži.
    #[Test]
    public function sablon_bez_polja_ime_ne_trazi_ime(): void
    {
        $this->odgovorModela = "Poštovani,\n\nPraksa dve nedelje.";

        $html = $this->napisi($this->sablon(false), ['tekst' => 'Praksa dve nedelje.']);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
    }

    // Prazno ime nema šta da se traži.
    #[Test]
    public function prazno_ime_se_ne_trazi(): void
    {
        $this->odgovorModela = "Poštovani,\n\nPraksa dve nedelje.\n\nS poštovanjem,";

        $html = $this->napisi($this->sablon(), ['ime' => '', 'tekst' => 'Praksa dve nedelje.']);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
    }
}
