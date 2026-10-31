<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;
use Tests\Podrska\HvatacDnevnika;

// Paket 95/T3 (PITANJA-92 3): kad OLLAMA_ADRESA nije petlja ni privatna mreža, pomoćnik i „Napiši moj" ne zovu model i kažu isto što kad model ne radi; pitanje ne ide u dnevnik.
#[Group('baza')]
class PomocnikNaTudjojOllamiNeRadiTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const LOKALNA = 'http://127.0.0.1:11434/api/chat';

    private const TUDJA = 'https://ollama.example.test:11434';

    private const PITANJE = 'Ima li posla u Nišu za Rome?';

    private const UNOSI = ['ime' => 'Ana Anić', 'tekst' => "Molim za pomoć.\nHvala.", 'datum' => '11.10.2026.'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function sablon(): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Molba', 'objasnjenje' => 'Za dopis u kojem nekoga moliš.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => 'Tvoje ime i prezime.', 'dugo' => false],
                ['kljuc' => 'tekst', 'oznaka' => 'Šta moliš', 'objasnjenje' => 'Svojim rečima.', 'dugo' => true],
                ['kljuc' => 'datum', 'oznaka' => 'Datum', 'objasnjenje' => null, 'dugo' => false],
            ],
            'tekst' => "{ime}\n\nMolim: {tekst}\n\n{datum}",
        ]);
    }

    private function pitaj(): TestResponse
    {
        return $this->post(route('pomocnik.pitaj'), ['pitanje' => self::PITANJE]);
    }

    private function napisi(): TestResponse
    {
        return $this->post(route('pomocnik.napisi', $this->sablon()->slug), ['polje' => self::UNOSI]);
    }

    /** Lokalni model koji odgovara na formular (prazan, pa pomoćnik kaže „nemam podatak") ili ne odgovara. */
    private function lokalniModel(bool $radi): void
    {
        Http::fake([self::LOKALNA => fn (Request $zahtev) => $radi
            ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
            : throw new ConnectionException('nema veze')]);
    }

    #[Test]
    public function pomocnik_na_tudjoj_adresi_kaze_isto_sto_i_kad_model_ne_radi_a_nista_ne_salje(): void
    {
        $this->lokalniModel(false);
        $kadNeRadi = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('{}')]);
        config()->set('ollama.adresa', self::TUDJA);
        $dnevnik = new HvatacDnevnika;

        $naTudjoj = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        $this->assertStringContainsString(PrikazPomocnika::NE_RADI, $naTudjoj);
        $this->assertSame($kadNeRadi, $naTudjoj);
        Http::assertNothingSent();
        $this->assertCount(1, $dnevnik->poruke);
        $this->assertSame('Ollama: adresa nije lokalna, poziv je odbijen.', $dnevnik->poruke[0]->message);
        $this->assertSame([], $dnevnik->poruke[0]->context);
    }

    // Ogledalo: na lokalnoj adresi isto pitanje dobija odgovor (ovde „nemam podatak", jer baza nema zapisa), a ne poruku da pomoćnik ne radi.
    #[Test]
    public function pomocnik_na_lokalnoj_adresi_radi(): void
    {
        $this->lokalniModel(true);

        $tekst = $this->vidljivTekst($this->pitaj()->assertOk()->getContent());

        $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $tekst);
        Http::assertSentCount(1);
    }

    #[Test]
    public function napisi_moj_na_tudjoj_adresi_daje_sklopljen_sablon_a_nista_ne_salje(): void
    {
        config()->set('ollama.adresa', self::TUDJA);
        Http::fake(['*' => Http::response('{}')]);

        $html = $this->napisi()->assertOk()->getContent();

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertStringNotContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        Http::assertNothingSent();
    }

    // Ogledalo: isti zahtev na lokalnoj adresi dobija tekst koji je napisao model.
    #[Test]
    public function napisi_moj_na_lokalnoj_adresi_radi(): void
    {
        Http::fake([self::LOKALNA => Http::response(['message' => ['content' => "Ana Anić\n\nMolim: Molim za pomoć.\nHvala.\n\n11.10.2026."]])]);

        $html = $this->napisi()->assertOk()->getContent();

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        Http::assertSentCount(1);
    }
}
