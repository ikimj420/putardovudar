<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Pomocnik\Pomocnik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 67/T13 (Ivan, 11.10.2026.): kad provera odbije odgovor modela i nađe se više vrsta, „Evo šta sam našao za tebe:" stoji jednom na vrhu, a ispod su sve stavke redom
// (prilike, vodiči, organizacije, kalendar); ranije se red ponavljao iznad svakog bloka.
#[Group('baza')]
class PomocnikJedanNaslovTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const PITANJE = 'Šta ima o Dositeju?';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
        // Odgovor modela sa izmišljenim datumom ne prolazi proveru, pa ide gotov tekst sa svim vrstama.
        Http::fake([self::OLLAMA => fn (Request $zahtev) => array_key_exists('format', $zahtev->data())
            ? Http::response(['message' => ['content' => json_encode(['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => ['Dositeja']])]])
            : Http::response(['message' => ['content' => 'Prijave su do 3. januara 2031.']])]);
    }

    private function sveVrste(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Stipendija Dositeja', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-12-15', 'kratak_opis' => 'Za studente.']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za Dositeja']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Fond Dositeja', 'kratak_opis' => 'Pomoć.', 'usluge' => ['Dositeja']]);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Dositeja kalendar', 'otvoreno_od' => '2025-12-22', 'otvoreno_do' => '2026-01-26']);
    }

    #[Test]
    public function kad_se_nadju_sve_cetiri_vrste_naslov_stoji_jednom_a_ispod_su_sve_stavke_redom(): void
    {
        $this->sveVrste();

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertSame('datum kog nema u zapisima', $odgovor->razlogSablona);
        $this->assertSame(
            Pomocnik::SABLON_NASLOV."\n• Stipendija Dositeja — Rok: 15.12.2026.\n• Kako se prijaviti za Dositeja\n• Fond Dositeja\n• Dositeja kalendar — Prošli put: od 22.12.2025. do 26.01.2026.",
            $odgovor->tekst,
        );
        $this->assertSame(1, substr_count($odgovor->tekst, Pomocnik::SABLON_NASLOV));
        $this->assertCount(4, $odgovor->izvori);
    }

    // Ogledalo: sa jednom vrstom je isto što i ranije (naslov i stavka), a bez ičega nema ni naslova.
    #[Test]
    public function sa_jednom_vrstom_je_naslov_i_stavka_kao_pre(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za Dositeja']);

        $this->assertSame(Pomocnik::SABLON_NASLOV."\n• Kako se prijaviti za Dositeja", app(Pomocnik::class)->odgovori(self::PITANJE)->tekst);
    }

    // Na strani pomoćnika i u prozoru čovek vidi naslov tačno jednom, a kartice izvora ispod su sve četiri.
    #[Test]
    public function strana_i_prozor_pokazuju_naslov_tacno_jednom(): void
    {
        $this->sveVrste();

        foreach ([$this, $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])] as $zahtev) {
            $html = $zahtev->post(route('pomocnik.pitaj'), ['pitanje' => self::PITANJE])->assertOk()->getContent();
            $tekst = $this->vidljivTekst($html);

            $this->assertSame(1, substr_count($tekst, Pomocnik::SABLON_NASLOV));
            foreach (['Stipendija Dositeja', 'Kako se prijaviti za Dositeja', 'Fond Dositeja', 'Dositeja kalendar'] as $stavka) {
                $this->assertGreaterThanOrEqual(2, substr_count($tekst, $stavka), $stavka.': u tekstu odgovora i u kartici izvora');
            }
        }
    }
}
