<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Pomocnik\Pomocnik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 67/T16: odgovor modela sa obećanjem „javiću ti" ne stiže do čoveka: pomoćnik daje gotov tekst sa karticama.
#[Group('baza')]
class ObecanjeUPrvomLicuUOdgovoruTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private string $odgovorModela = '';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15']);
        // Http::fake ostaje na prvom postavljenom odgovoru, pa se postavlja jednom, a odgovor se menja kroz svojstvo.
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => $this->odgovorModela]]);
        }]);
    }

    #[Test]
    public function javicu_ti_bez_kalendara_se_menja_karticama(): void
    {
        $this->odgovorModela = 'Našao sam posao u Nišu. Javiću ti kad se pojavi nešto novo.';

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertNotNull($odgovor->razlogSablona);
        $this->assertSame('obećanje u prvom licu', $odgovor->razlogSablona);
        $this->assertStringNotContainsString('Javiću', $odgovor->tekst);
        $this->assertSame(['Radnik u skladištu'], array_map(fn ($izvor) => $izvor->naslov, $odgovor->izvori));
    }

    // Paket 70/T8: isto i za „javićemo ti".
    #[Test]
    public function javicemo_ti_bez_kalendara_se_menja_karticama(): void
    {
        $this->odgovorModela = 'Našao sam posao u Nišu. Javićemo ti kad se pojavi nešto novo.';

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertNotNull($odgovor->razlogSablona);
        $this->assertSame('obećanje u prvom licu', $odgovor->razlogSablona);
        $this->assertStringNotContainsString('Javićemo', $odgovor->tekst);
    }

    // Ogledalo: isti odgovor bez obećanja prolazi.
    #[Test]
    public function rok_u_odgovoru_prolazi(): void
    {
        $this->odgovorModela = 'Našao sam posao u Nišu. Rok je 15.11.2026.';

        $odgovor = app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');

        $this->assertNull($odgovor->razlogSablona);
        $this->assertSame('Našao sam posao u Nišu. Rok je 15.11.2026.', $odgovor->tekst);
    }
}
