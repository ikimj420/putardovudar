<?php

namespace Tests\Feature\Baza;

use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 54: klik bilo gde na kartici vodi na stranu. Veza ostaje jedna (naslov) i razvlači se preko kartice (CSS), pa čitač ekrana ne čuje isti link dvaput;
// ostale veze u kartici (npr. „Pozovi", zvanični izvor) stoje iznad nje. Ponašanje klikom je izmereno u pregledaču (izveštaj), ovde se čuva sklop.
#[Group('baza')]
class CelaKarticaJeVezaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return list<string> HTML svake kartice na strani */
    private function kartice(string $html): array
    {
        preg_match_all('#<article class="kartica[^"]*">.*?</article>#s', $html, $nalazi);

        return $nalazi[0];
    }

    /** @return list<array{0: string, 1: string}> klase i adresa svake veze u kartici */
    private function veze(string $kartica): array
    {
        preg_match_all('#<a\b([^>]*)>#', $kartica, $nalazi);

        return array_map(function (string $atributi): array {
            preg_match('#class="([^"]*)"#', $atributi, $klasa);
            preg_match('#href="([^"]*)"#', $atributi, $adresa);

            return [$klasa[1] ?? '', html_entity_decode($adresa[1] ?? '')];
        }, $nalazi[1]);
    }

    /** @return array<string, array{0: string, 1: string}> strana => [adresa strane, adresa na koju vodi kartica] */
    private function strane(): array
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'rok' => '2026-12-15', 'link_izvora' => 'https://fond.rs/dositeja']);
        $vodic = Vodic::factory()->objavljen()->create();
        $organizacija = Organizacija::factory()->objavljena()->create(['telefon' => '011/123-456']);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Dositeja prošle godine', 'otvoreno_do' => '2026-01-26', 'link_izvora' => 'https://fond.rs/dositeja']);

        return [
            'prilike' => [route('prilike.index'), route('prilike.show', $prilika->slug)],
            'vodiči' => [route('vodici.index'), route('vodici.show', $vodic->slug)],
            'organizacije' => [route('organizacije.index'), route('organizacije.show', $organizacija->slug)],
            'kalendar' => [route('kalendar.index'), route('prilike.show', $prilika->slug)],
        ];
    }

    // Svaka kartica ima tačno jednu vezu sa klasom i ona vodi na stranu; ista adresa se u kartici ne ponavlja.
    #[Test]
    public function svaka_kartica_ima_jednu_vezu_na_stranu_i_ta_adresa_se_ne_ponavlja(): void
    {
        $obidjeno = 0;

        foreach ($this->strane() as $ime => [$spisak, $adresa]) {
            $kartice = $this->kartice($this->get($spisak)->assertOk()->getContent());

            $this->assertNotEmpty($kartice, $ime);

            foreach ($kartice as $kartica) {
                $obidjeno++;
                $veze = $this->veze($kartica);
                $glavne = array_filter($veze, fn (array $veza) => $veza[0] === 'kartica-veza');

                $this->assertCount(1, $glavne, $ime.': jedna veza na stranu');
                $this->assertSame($adresa, array_values($glavne)[0][1], $ime);
                $this->assertSame(1, count(array_filter($veze, fn (array $veza) => $veza[1] === $adresa)), $ime.': ista adresa jednom');
            }
        }

        $this->assertGreaterThanOrEqual(4, $obidjeno);
    }

    // Dugmad i ostale veze u kartici nisu „kartica-veza": „Pozovi" i zvanični izvor rade svako za sebe.
    #[Test]
    public function dugme_pozovi_i_zvanicni_izvor_su_posebne_veze_u_kartici(): void
    {
        $this->strane();

        $organizacija = $this->kartice($this->get(route('organizacije.index'))->getContent())[0];
        $veze = $this->veze($organizacija);
        $this->assertSame(['kartica-veza', 'dugme-veza'], array_column($veze, 0));
        $this->assertSame('tel:011123456', $veze[1][1]);

        $kalendar = $this->kartice($this->get(route('kalendar.index'))->getContent())[0];
        $this->assertSame(['kartica-veza', ''], array_column($this->veze($kalendar), 0));
        $this->assertSame('https://fond.rs/dositeja', $this->veze($kalendar)[1][1]);
    }

    // Kartica kalendara koja ne vodi nikud (ista stvar nije sada otvorena) nema ni vezu preko cele kartice; zvanični izvor ostaje obična veza.
    #[Test]
    public function kartica_kalendara_bez_otvorene_prilike_nema_vezu_preko_cele_kartice(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Bez prilike', 'otvoreno_do' => '2026-01-26', 'link_izvora' => 'https://fond.rs/druga']);

        $kartice = $this->kartice($this->get(route('kalendar.index'))->assertOk()->getContent());

        $this->assertCount(1, $kartice);
        $this->assertSame([''], array_column($this->veze($kartice[0]), 0));
        $this->assertStringContainsString('<h3>Bez prilike</h3>', $kartice[0]);
    }

    // Izvori uz odgovor pomoćnika: naslov je veza preko kartice, zvanični izvor je posebna veza; kartica „Odgovor" nema vezu.
    #[Test]
    public function izvori_uz_odgovor_pomocnika_su_cele_kartice_a_odgovor_nije(): void
    {
        Http::preventStrayRequests();
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'kratak_opis' => 'Rad u skladištu.', 'rok' => '2026-12-15', 'naziv_izvora' => 'Fond', 'link_izvora' => 'https://fond.rs/skladiste']);
        Http::fake([self::OLLAMA => fn (Request $zahtev) => array_key_exists('format', $zahtev->data())
            ? Http::response(['message' => ['content' => json_encode(['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => ['skladištu']])]])
            : Http::response(['message' => ['content' => 'Ima posla u skladištu.']])]);

        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u skladištu?'])->assertOk()->getContent();
        $kartice = $this->kartice($html);

        $this->assertCount(1, $kartice);
        $this->assertSame(['kartica-veza', ''], array_column($this->veze($kartice[0]), 0));
        $this->assertSame(route('prilike.show', $prilika->slug), $this->veze($kartice[0])[0][1]);
        $this->assertStringContainsString('<section class="kartica odgovor">', $html);
        $this->assertSame([], $this->veze((string) preg_replace('#<article.*?</article>|<footer.*?</footer>#s', '', (string) strstr($html, '<section class="kartica odgovor">'))));
    }

    // Stil: veza se razvlači preko kartice (kartica je njen položaj), ostale veze su iznad, a naslov ne dobija sopstveni položaj.
    #[Test]
    public function stil_razvlaci_vezu_preko_kartice_a_ostale_veze_drzi_iznad(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.kartica \{[^}]*position: relative;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.kartica-veza::after \{[^}]*content: "";[^}]*position: absolute;[^}]*inset: 0;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.kartica a:not\(\.kartica-veza\) \{[^}]*position: relative;[^}]*z-index: 1;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.kartica-veza:focus-visible::after \{[^}]*outline: 3px solid/', $stil);
        // Ogledalo: veza naslova sama nije položena, inače bi se preko kartice razvukla samo preko nje.
        preg_match_all('/\n\.kartica h[23] a \{([^}]*)\}/', $stil, $pravila);
        $this->assertNotEmpty($pravila[1]);
        foreach ($pravila[1] as $telo) {
            $this->assertStringNotContainsString('position:', $telo);
        }
    }
}
