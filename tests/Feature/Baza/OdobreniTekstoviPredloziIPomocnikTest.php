<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Support\PrikazPomocnika;
use App\Support\PrikazPredloga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\BazaTestCase;

// Paket 97/T3 (Ivan, 31.10.2026.; nalaz 9 iz PITANJA-92): doslovni tekstovi strane „Predloži priliku" i napomena pomoćnika, upisani ovde slovo po slovo, a ne iz konstanti.
// Ostali testovi porede sa konstantama, pa bi izmenjen tekst ostao zelen; ovaj je jedini koji pocrveni kad se odobreni tekst promeni. Zato sve što stoji ovde menja samo Ivan.
#[Group('baza')]
class OdobreniTekstoviPredloziIPomocnikTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const LINK = 'https://primer.rs/konkurs-za-stipendije';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @param  array<string, mixed>  $podaci */
    private function posalji(array $podaci, string $adresa = '198.51.100.7'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $adresa])->post(route('predlozi.posalji'), $podaci);
    }

    #[Test]
    public function svi_tekstovi_strane_predlozi_su_doslovno_odobreni(): void
    {
        $this->assertSame([
            'NASLOV' => 'Predloži priliku',
            'UVOD' => 'Znaš za posao, stipendiju ili konkurs koji nije na sajtu? Pošalji nam link.',
            'OZNAKA_LINKA' => 'Link do prilike',
            'OBJASNJENJE_LINKA' => 'Adresa strane na kojoj piše o prilici.',
            'PRIMER_LINKA' => 'https://primer.rs/konkurs-za-stipendije',
            'OZNAKA_NAPOMENE' => 'Napomena (nije obavezna)',
            'OBJASNJENJE_NAPOMENE' => 'Šta je to i do kada traje, ako znaš. Najviše 500 znakova.',
            'PRIMER_NAPOMENE' => 'Na primer: Konkurs za stipendije, prijave su do 15. novembra.',
            'NE_CUVAMO' => 'Čuvamo samo link i napomenu, da bismo proverili priliku. Ne upisuj lične podatke.',
            'DUGME' => 'Pošalji',
            'HVALA' => 'Hvala! Predlog je stigao.',
            'GRESKA_PRAZNO' => 'Upiši link do prilike.',
            'GRESKA_LINK' => 'Link nije ispravan. Mora da počne sa http:// ili https://.',
            'GRESKA_LINK_DUGACAK' => 'Link može imati najviše 2048 znakova.',
            'GRESKA_NAPOMENA_DUGACKA' => 'Napomena može imati najviše 500 znakova.',
            'PREVISE_PREDLOGA' => 'Predlozi stižu prebrzo. Probaj ponovo za minut.',
            'VEZA_SA_PRILIKA' => 'Znaš za priliku koje nema? Predloži je.',
        ], (new ReflectionClass(PrikazPredloga::class))->getConstants());
    }

    // Isti tekstovi, ali kako ih posetilac stvarno vidi: na formi, uz greške, posle slanja i na strani „Prilike".
    #[Test]
    public function posetilac_vidi_doslovno_odobrene_tekstove_na_strani_predlozi(): void
    {
        $this->get(route('predlozi.forma'))->assertOk()->assertSeeInOrder([
            'Predloži priliku',
            'Znaš za posao, stipendiju ili konkurs koji nije na sajtu? Pošalji nam link.',
            'Link do prilike',
            'Adresa strane na kojoj piše o prilici.',
            'Napomena (nije obavezna)',
            'Šta je to i do kada traje, ako znaš. Najviše 500 znakova.',
            'Pošalji',
            'Čuvamo samo link i napomenu, da bismo proverili priliku. Ne upisuj lične podatke.',
        ])->assertSee('https://primer.rs/konkurs-za-stipendije', false)->assertSee('Na primer: Konkurs za stipendije, prijave su do 15. novembra.', false);

        $this->posalji([], '198.51.100.1')->assertStatus(422)->assertSee('Upiši link do prilike.');
        $this->posalji(['link' => 'nije link'], '198.51.100.2')->assertStatus(422)->assertSee('Link nije ispravan. Mora da počne sa http:// ili https://.');
        $this->posalji(['link' => 'https://primer.rs/'.str_repeat('a', 2049 - strlen('https://primer.rs/'))], '198.51.100.3')->assertStatus(422)->assertSee('Link može imati najviše 2048 znakova.');
        $this->posalji(['link' => self::LINK, 'napomena' => str_repeat('a', 501)], '198.51.100.4')->assertStatus(422)->assertSee('Napomena može imati najviše 500 znakova.');

        // Ogledalo: tačno na granicama prolazi, bez poruka o dužini.
        $this->posalji(['link' => 'https://primer.rs/'.str_repeat('a', 2048 - strlen('https://primer.rs/')), 'napomena' => str_repeat('a', 500)], '198.51.100.5')->assertRedirect(route('predlozi.forma'));
        $this->get(route('predlozi.forma'))->assertSee('Hvala! Predlog je stigao.')->assertDontSee('najviše 2048 znakova.')->assertDontSee('Napomena može imati najviše 500 znakova.');

        foreach (range(1, (int) config('predlozi.slanja_u_minuti')) as $broj) {
            $this->posalji(['link' => self::LINK.'/'.$broj], '198.51.100.6');
        }
        $this->posalji(['link' => self::LINK], '198.51.100.6')->assertStatus(429)->assertSee('Predlozi stižu prebrzo. Probaj ponovo za minut.');

        $this->get(route('prilike.index'))->assertOk()->assertSee('Znaš za priliku koje nema? Predloži je.');
    }

    #[Test]
    public function napomene_napisi_moj_su_doslovno_odobrene(): void
    {
        $this->assertSame('Tekst je napisan iz tvojih odgovora. Proveri ga pre nego što ga pošalješ.', PrikazPomocnika::NAPOMENA_NAPISAN);
        $this->assertSame('Složen je šablon sa tvojim odgovorima. Linije označene crtama popuni olovkom.', PrikazPomocnika::NAPOMENA_SLOZEN);

        $sablon = Sablon::factory()->objavljen()->create([
            'naslov' => 'Molba', 'objasnjenje' => 'Za dopis u kojem nekoga moliš.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => 'Tvoje ime i prezime.', 'dugo' => false],
                ['kljuc' => 'tekst', 'oznaka' => 'Šta moliš', 'objasnjenje' => 'Svojim rečima.', 'dugo' => true],
            ],
            'tekst' => "{ime}\n\nMolim: {tekst}",
        ]);
        $unosi = ['polje' => ['ime' => 'Ana Anić', 'tekst' => 'Molim za pomoć.']];

        // Model napiše tekst: stoji prva napomena, i samo ona.
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => "Ana Anić\n\nMolim: Molim za pomoć."]])]);
        $napisan = $this->post(route('pomocnik.napisi', $sablon->slug), $unosi)->assertOk()->getContent();
        $this->assertStringContainsString('Tekst je napisan iz tvojih odgovora. Proveri ga pre nego što ga pošalješ.', $napisan);
        $this->assertStringNotContainsString('Složen je šablon sa tvojim odgovorima.', $napisan);

        // Model ne radi: stoji složen šablon, i samo druga napomena.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::OLLAMA => fn () => throw new ConnectionException('nema veze')]);
        $slozen = $this->post(route('pomocnik.napisi', $sablon->slug), $unosi)->assertOk()->getContent();
        $this->assertStringContainsString('Složen je šablon sa tvojim odgovorima. Linije označene crtama popuni olovkom.', $slozen);
        $this->assertStringNotContainsString('Tekst je napisan iz tvojih odgovora.', $slozen);
    }
}
