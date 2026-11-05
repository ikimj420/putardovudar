<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\RezultatPretrage;
use App\Support\PoredjenjeTeksta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 102/T2 (Ivan „piši", 05.11.2026., PITANJA-101 2): reč koju vrsta priznaje kao svoju („kurs" za obuku) ne mora da piše u
// tekstu prilike, isto kao „obuka". Formular je onaj koji pravi Claude vraća za „kurs" (PROBA-PAKET-101.md).
#[Group('baza')]
class RecVrsteNeSuzavaPretraguSaVrstomTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
        $this->prilika('Obuka za digitalne veštine', VrstaPrilike::Obuka, null, 'Besplatna onlajn obuka za rad na računaru.');
        $this->prilika('Radionica: kako da napišeš CV', VrstaPrilike::Obuka, 'Beograd', 'Dvočasovna radionica o pisanju biografije.');
        $this->prilika('Konobar sa iskustvom', VrstaPrilike::Posao, 'Novi Sad', 'Restoran u centru grada traži konobara.');
        $this->prilika('Kurs plesa u domu kulture', VrstaPrilike::Drugo, 'Beograd', 'Kurs za sve uzraste.');
        $this->prilika('Kurs koji je nacrt', VrstaPrilike::Obuka, null, 'Kurs.', StatusPrilike::Nacrt);
    }

    private function prilika(string $naslov, VrstaPrilike $vrsta, ?string $mesto, string $opis, StatusPrilike $status = StatusPrilike::Objavljeno): void
    {
        Prilika::factory()->objavljena()->create([
            'naslov' => $naslov, 'vrsta' => $vrsta, 'mesto' => $mesto, 'kratak_opis' => $opis, 'opis' => '', 'rok' => '2026-12-15', 'status' => $status,
        ]);
    }

    /** @param  array<string, mixed>  $forma */
    private function pitaj(string $pitanje, array $forma): RezultatPretrage
    {
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor(json_encode($forma + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], JSON_UNESCAPED_UNICODE))]);

        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    #[Test]
    public function kurs_nalazi_obuke_iako_u_tekstu_ne_pise_kurs(): void
    {
        $rezultat = $this->pitaj('kurs', ['vrsta' => 'obuka', 'kljucne_reci' => ['kurs']]);

        $this->assertSame(['Obuka za digitalne veštine', 'Radionica: kako da napišeš CV'], $rezultat->zapisi->pluck('naslov')->sort()->values()->all());
        // Neprazan skup bez reči: nijedna nađena obuka nema „kurs" u tekstu, a „kurs" je i dalje u formularu.
        $this->assertSame(['kurs'], $rezultat->formular->kljucneReci);
        foreach ($rezultat->zapisi as $prilika) {
            $this->assertStringNotContainsString('kurs', PoredjenjeTeksta::normalizuj($prilika->naslov.' '.$prilika->kratak_opis.' '.$prilika->opis));
        }
    }

    #[Test]
    public function radionica_u_beogradu_nalazi_obuku_u_beogradu(): void
    {
        $rezultat = $this->pitaj('radionice u Beogradu', ['vrsta' => 'obuka', 'grad' => 'Beograd', 'kljucne_reci' => ['radionice']]);

        // Ogledalo: vrsta i dalje sužava, pa „Kurs plesa" (vrsta drugo, isti grad) ne ulazi.
        $this->assertSame(['Radionica: kako da napišeš CV'], $rezultat->zapisi->pluck('naslov')->all());
    }

    // Ogledalo: izraz sa još jednom rečju i dalje traži obe reči u tekstu.
    #[Test]
    public function kurs_uz_drugu_rec_i_dalje_trazi_tu_rec(): void
    {
        $this->prilika('Engleski za početnike', VrstaPrilike::Obuka, null, 'Kurs engleskog jezika.');

        $rezultat = $this->pitaj('kurs engleskog', ['vrsta' => 'obuka', 'kljucne_reci' => ['kurs engleskog']]);

        $this->assertSame(['Engleski za početnike'], $rezultat->zapisi->pluck('naslov')->all());
    }

    // Ogledalo: „kurs" nije reč posla, pa uz vrstu posao i dalje mora da piše u tekstu.
    #[Test]
    public function rec_druge_vrste_i_dalje_suzava(): void
    {
        $rezultat = $this->pitaj('kurs za posao', ['vrsta' => 'posao', 'kljucne_reci' => ['kurs']]);

        $this->assertTrue($rezultat->formular->vrstaJePomenuta);
        $this->assertTrue($rezultat->zapisi->isEmpty());
    }

    // Ogledalo: bez vrste (rezervna pretraga, paket 30) reč ostaje uslov, pa „kurs u Novom Sadu" ne vraća konobara iz Novog Sada.
    #[Test]
    public function bez_vrste_kurs_ostaje_rec_u_tekstu(): void
    {
        $rezultat = $this->pitaj('kurs u Novom Sadu', ['vrsta' => 'obuka', 'grad' => 'Novi Sad', 'kljucne_reci' => ['kurs']]);

        $this->assertFalse($rezultat->formular->vrstaJePomenuta);
        $this->assertTrue($rezultat->zapisi->isEmpty());
        $this->assertTrue($rezultat->nemaPodatak());
        $this->assertSame(1, Prilika::javne()->where('mesto', 'Novi Sad')->count());
    }

    // Rezervna pretraga i dalje radi kao u paketu 30: bez obuke u gradu, kurs druge vrste iz tog grada se nalazi po reči.
    #[Test]
    public function bez_obuke_u_gradu_nalazi_se_kurs_druge_vrste(): void
    {
        $this->prilika('Kurs šivenja', VrstaPrilike::Drugo, 'Niš', 'Besplatno za odrasle.');
        $this->prilika('Radnik u skladištu', VrstaPrilike::Posao, 'Niš', 'Puno radno vreme.');

        $rezultat = $this->pitaj('kurs u Nišu', ['vrsta' => 'obuka', 'grad' => 'Niš', 'kljucne_reci' => ['kurs']]);

        $this->assertSame(['Kurs šivenja'], $rezultat->zapisi->pluck('naslov')->all());
    }
}
