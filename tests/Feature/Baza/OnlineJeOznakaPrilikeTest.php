<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 102/T3 (Ivan „piši", 05.11.2026., PITANJA-101 3): „online" i „onlajn" u pitanju znače prilike označene kao online,
// a ne reč koja mora da piše u tekstu. „Obuka za digitalne veštine" je ista kao u probnoj bazi: označena, a u tekstu piše „onlajn".
#[Group('baza')]
class OnlineJeOznakaPrilikeTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function prilika(string $naslov, VrstaPrilike $vrsta, bool $online, string $opis, StatusPrilike $status = StatusPrilike::Objavljeno): void
    {
        Prilika::factory()->objavljena()->create([
            'naslov' => $naslov, 'vrsta' => $vrsta, 'mesto' => $online ? null : 'Beograd', 'online' => $online, 'kratak_opis' => $opis, 'opis' => '', 'rok' => '2026-12-15', 'status' => $status,
        ]);
    }

    private function prilike(): void
    {
        $this->prilika('Obuka za digitalne veštine', VrstaPrilike::Obuka, true, 'Besplatna onlajn obuka za rad na računaru.');
        $this->prilika('Radionica: kako da napišeš CV', VrstaPrilike::Obuka, false, 'Dvočasovna radionica o pisanju biografije.');
        // U tekstu piše „online", ali nije označena: reč iz teksta nije oznaka.
        $this->prilika('Prodavac u maloprodaji', VrstaPrilike::Posao, false, 'Rad u prodavnici i online prodaja.');
        $this->prilika('Online konkurs koji je nacrt', VrstaPrilike::Konkurs, true, 'Online.', StatusPrilike::Nacrt);
    }

    /** @param  array<string, mixed>  $forma */
    private function pitaj(string $pitanje, array $forma): RezultatPretrage
    {
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor(json_encode($forma + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], JSON_UNESCAPED_UNICODE))]);

        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    #[Test]
    public function online_nalazi_priliku_oznacenu_kao_online(): void
    {
        $this->prilike();

        $rezultat = $this->pitaj('online', ['kljucne_reci' => ['online']]);

        $this->assertTrue($rezultat->formular->online);
        $this->assertSame(['Obuka za digitalne veštine'], $rezultat->zapisi->pluck('naslov')->all());
        // Neprazan skup: tri objavljene prilike, od kojih jedna ima „online" samo u tekstu.
        $this->assertSame(3, Prilika::javne()->count());
    }

    // Model reč „onlajn" i ne vrati: oznaka se čita iz pitanja.
    #[Test]
    public function onlajn_bez_kljucne_reci_nalazi_priliku_oznacenu_kao_online(): void
    {
        $this->prilike();

        $rezultat = $this->pitaj('Ima li nešto onlajn?', []);

        $this->assertTrue($rezultat->formular->online);
        $this->assertSame(['Obuka za digitalne veštine'], $rezultat->zapisi->pluck('naslov')->all());
    }

    // Merilo iz T2 i T3 zajedno: formular je onaj koji pravi Claude vraća za „online kurs" (PROBA-PAKET-101.md).
    #[Test]
    public function online_kurs_nalazi_online_obuku(): void
    {
        $this->prilike();

        $rezultat = $this->pitaj('online kurs', ['vrsta' => 'obuka', 'kljucne_reci' => ['online kurs']]);

        $this->assertSame(['Obuka za digitalne veštine'], $rezultat->zapisi->pluck('naslov')->all());
        $this->assertFalse($rezultat->nemaPodatak());
    }

    // Ogledalo: bez te reči u pitanju nema oznake, pa obuka nalazi i online i obuku uživo.
    #[Test]
    public function bez_reci_online_nema_oznake(): void
    {
        $this->prilike();

        $rezultat = $this->pitaj('obuka', ['vrsta' => 'obuka']);

        $this->assertFalse($rezultat->formular->online);
        $this->assertSame(['Obuka za digitalne veštine', 'Radionica: kako da napišeš CV'], $rezultat->zapisi->pluck('naslov')->sort()->values()->all());
    }

    // Rezervna pretraga bez nagađane vrste (paket 30) zadržava oznaku: online kurs druge vrste da, kurs uživo ne.
    #[Test]
    public function rezervna_pretraga_zadrzava_oznaku_online(): void
    {
        $this->prilika('Kurs programiranja', VrstaPrilike::Drugo, true, 'Kurs preko interneta.');
        $this->prilika('Kurs plesa', VrstaPrilike::Drugo, false, 'Kurs u domu kulture.');

        $rezultat = $this->pitaj('online kurs', ['vrsta' => 'obuka', 'kljucne_reci' => ['online kurs']]);

        $this->assertSame(['Kurs programiranja'], $rezultat->zapisi->pluck('naslov')->all());
    }

    // Bez online obuka, „online obuka" je „nemam podatak", a ne „nema otvorenih obuka": obuka uživo postoji.
    #[Test]
    public function online_obuka_bez_online_obuka_ne_kaze_da_obuka_nema(): void
    {
        $this->prilika('Radionica: kako da napišeš CV', VrstaPrilike::Obuka, false, 'Dvočasovna radionica o pisanju biografije.');
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor(json_encode(['vrsta' => 'obuka', 'grad' => null, 'kome' => null, 'kljucne_reci' => []]))]);

        $odgovor = app(Pomocnik::class)->odgovori('online obuka');

        $this->assertSame(Pomocnik::NEMAM_PODATAK, $odgovor->tekst);
        $this->assertTrue($odgovor->nemaPodatak);
    }

    // Ogledalo: „obuka" bez ijedne otvorene obuke i dalje kaže da obuka nema.
    #[Test]
    public function obuka_bez_ijedne_obuke_kaze_da_obuka_nema(): void
    {
        $this->prilika('Prodavac u maloprodaji', VrstaPrilike::Posao, false, 'Rad u prodavnici.');
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor(json_encode(['vrsta' => 'obuka', 'grad' => null, 'kome' => null, 'kljucne_reci' => []]))]);

        $odgovor = app(Pomocnik::class)->odgovori('obuka');

        $this->assertSame(sprintf(Pomocnik::NEMA_OTVORENIH, 'obuka'), $odgovor->tekst);
    }
}
