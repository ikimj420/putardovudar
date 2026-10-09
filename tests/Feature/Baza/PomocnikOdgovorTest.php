<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Ollama\OllamaNedostupna;
use App\Services\Pomocnik\OdgovorPomocnika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\ProveraOdgovora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class PomocnikOdgovorTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private Prilika $prilika;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 9, 12, 0, 0, 'Europe/Belgrade'));
        // Adresa „localhost" nema domen, pa je naša strana vidljiva proveri linkova tek uz pravu adresu sajta.
        app('url')->forceRootUrl('https://nas-sajt.rs');
        $this->prilika = Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'opis' => 'Rad u skladištu.',
            'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
    }

    /**
     * Prvi poziv (format json) vraća formular, drugi (bez formata) vraća tekst odgovora.
     *
     * @param  string|callable|null  $tekst
     */
    private function model(mixed $tekst, bool $formularRadi = true): void
    {
        Http::fake([self::OLLAMA => function (Request $zahtev) use ($tekst, $formularRadi) {
            if (array_key_exists('format', $zahtev->data())) {
                return $formularRadi
                    ? Http::response(['message' => ['content' => json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []])]])
                    : throw new ConnectionException('nema veze');
            }

            return is_callable($tekst) ? $tekst() : Http::response(['message' => ['content' => $tekst]]);
        }]);
    }

    private function pozivaModela(bool $zaOdgovor): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA && array_key_exists('format', $zahtev->data()) !== $zaOdgovor)->count();
    }

    private function pitaj(): OdgovorPomocnika
    {
        return app(Pomocnik::class)->odgovori('Ima li posla u Nišu?');
    }

    #[Test]
    public function ispravan_odgovor_modela_ostaje_a_izvore_dodaje_kod(): void
    {
        $this->model('U Nišu postoji posao radnika u skladištu, prijave su do 15. novembra 2026.');

        $odgovor = $this->pitaj();

        $this->assertSame('U Nišu postoji posao radnika u skladištu, prijave su do 15. novembra 2026.', $odgovor->tekst);
        $this->assertFalse($odgovor->jeIzSablona());
        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertCount(1, $odgovor->izvori);

        $izvor = $odgovor->izvori[0];

        $this->assertSame('Radnik u skladištu', $izvor->naslov);
        $this->assertSame('Rok: 15.11.2026.', $izvor->rok);
        $this->assertSame(route('prilike.show', $this->prilika->slug), $izvor->adresa);
        $this->assertSame('Probni izvor', $izvor->zvanicniNaziv);
        $this->assertSame('https://primer.rs/skladiste-nis', $izvor->zvanicniLink);
    }

    /** @return array<string, array{0: string}> */
    public static function izmisljeno(): array
    {
        return [
            'izmišljen datum sa godinom' => ['Prijave su do 20. oktobra 2026.'],
            'izmišljen datum brojevima' => ['Prijave su do 20.10.2026.'],
            'izmišljen datum bez godine' => ['Prijave su do 20. oktobra.'],
            'datum sa pogrešnom godinom' => ['Prijave su do 15. novembra 2027.'],
            'izmišljen ISO datum' => ['Rok je 2026-12-31.'],
            'izmišljen link' => ['Prijavi se na https://lazno.rs/prijava.'],
            'izmišljen domen bez adrese' => ['Više na lazno-sajt.com.'],
            'link sa tuđim domenom i našom putanjom' => ['Vidi https://tudji.rs/skladiste-nis.'],
            'prazan odgovor' => [''],
        ];
    }

    #[Test]
    #[DataProvider('izmisljeno')]
    public function izmisljen_datum_ili_link_menja_sablon_iz_zapisa(string $odgovorModela): void
    {
        $this->model($odgovorModela);

        $odgovor = $this->pitaj();

        $this->assertTrue($odgovor->jeIzSablona());
        $this->assertSame(Pomocnik::SABLON_NASLOV."\n• Radnik u skladištu — Rok: 15.11.2026.", $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);
    }

    /** @return array<string, array{0: string}> */
    public static function ispravno(): array
    {
        return [
            'datum rečima' => ['Prijave su do 15. novembra.'], 'datum brojevima' => ['Rok je 15.11.2026.'], 'datum bez godine' => ['Do 15.11. se prijavljuje.'],
            'ISO' => ['Rok: 2026-11-15.'], 'bez datuma' => ['Postoji posao radnika u skladištu u Nišu.'],
            'zvanični link iz zapisa' => ['Detalji: https://primer.rs/skladiste-nis'], 'domen zvaničnog izvora' => ['Detalji na primer.rs.'],
            'naša strana' => ['Detalji: '.'__NASA_STRANA__'], 'broj koji nije datum' => ['Plata je 10.5 hiljada, a rok je 15. novembra.'],
        ];
    }

    // Ogledalo: isti odgovori sa datumom i linkom iz zapisa prolaze.
    #[Test]
    #[DataProvider('ispravno')]
    public function datum_i_link_iz_zapisa_ostaju_u_odgovoru(string $odgovorModela): void
    {
        $odgovorModela = str_replace('__NASA_STRANA__', route('prilike.show', $this->prilika->slug), $odgovorModela);
        $this->model($odgovorModela);

        $odgovor = $this->pitaj();

        $this->assertFalse($odgovor->jeIzSablona(), (string) $odgovor->razlogSablona);
        $this->assertSame($odgovorModela, $odgovor->tekst);
    }

    #[Test]
    public function predugacak_odgovor_menja_sablon(): void
    {
        $this->model(str_repeat('Radnik u skladištu. ', 50));

        $this->assertSame('predug odgovor', $this->pitaj()->razlogSablona);
        $this->assertGreaterThan(ProveraOdgovora::NAJVISE_ZNAKOVA, 1000);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function razlozi(): array
    {
        return [
            'datum' => ['Do 20. oktobra.', 'datum kog nema u zapisima'],
            'link' => ['Vidi lazno.rs', 'link kog nema u zapisima'],
            'prazno' => ['', 'prazan odgovor'],
        ];
    }

    #[Test]
    #[DataProvider('razlozi')]
    public function razlog_sablona_kaze_sta_nije_prosao(string $odgovorModela, string $razlog): void
    {
        $this->model($odgovorModela);

        $this->assertSame($razlog, $this->pitaj()->razlogSablona);
    }

    #[Test]
    public function nema_zapisa_znaci_nemam_podatak_i_model_se_ne_zove_za_odgovor(): void
    {
        $this->model('Ovo ne sme da se pročita.');
        $this->prilika->update(['rok' => '2026-10-01']);

        $odgovor = $this->pitaj();

        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertSame(Pomocnik::NEMAM_PODATAK, $odgovor->tekst);
        $this->assertSame([], $odgovor->izvori);
        $this->assertSame(1, $this->pozivaModela(false));
        $this->assertSame(0, $this->pozivaModela(true));
    }

    // Ogledalo: kad zapis postoji, model se zove tačno jednom za formular i jednom za odgovor.
    #[Test]
    public function sa_zapisom_model_se_zove_jednom_za_formular_i_jednom_za_odgovor(): void
    {
        $this->model('Postoji posao u skladištu.');

        $this->pitaj();

        $this->assertSame(1, $this->pozivaModela(false));
        $this->assertSame(1, $this->pozivaModela(true));
    }

    #[Test]
    public function kad_model_ne_odgovori_na_drugi_poziv_stoji_sablon_a_izvori_ostaju(): void
    {
        $this->model(fn () => throw new ConnectionException('nema veze'));

        $odgovor = $this->pitaj();

        $this->assertSame('model ne odgovara', $odgovor->razlogSablona);
        $this->assertSame(Pomocnik::SABLON_NASLOV."\n• Radnik u skladištu — Rok: 15.11.2026.", $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);
    }

    #[Test]
    public function kad_model_ne_radi_ni_za_formular_izuzetak_ide_pozivaocu(): void
    {
        $this->model('x', formularRadi: false);

        $this->expectException(OllamaNedostupna::class);

        $this->pitaj();
    }

    #[Test]
    public function modelu_idu_samo_podaci_iz_zapisa_a_uputstvo_zabranjuje_linkove(): void
    {
        $this->model('Postoji posao.');

        $this->pitaj();

        Http::assertSent(function (Request $zahtev) {
            $telo = $zahtev->data();

            if ($zahtev->url() !== self::OLLAMA || array_key_exists('format', $telo)) {
                return false;
            }

            return $telo['options']['temperature'] === 0
                && str_contains($telo['messages'][0]['content'], 'samo iz priloženih prilika')
                && str_contains($telo['messages'][0]['content'], 'Ne navodi linkove')
                && str_contains($telo['messages'][1]['content'], 'Naslov: Radnik u skladištu; Vrsta: Posao; Mesto: Niš; Rok: Rok: 15.11.2026.; Kratak opis: Puno radno vreme.')
                && ! str_contains($telo['messages'][1]['content'], 'primer.rs');
        });
    }
}
