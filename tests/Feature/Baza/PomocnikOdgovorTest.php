<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Ollama\OllamaNedostupna;
use App\Services\Pomocnik\OdgovorPomocnika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\ProveraOdgovora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    // Pravi model je u probi (paket 19) odgovorio ćirilicom i mešano; sajt piše samo latinicom.
    #[Test]
    public function cirilicni_i_mesani_odgovor_modela_se_ispisuje_latinicom(): void
    {
        $this->model('Da tražите писмо, rok je 15. новембра 2026.');

        $odgovor = $this->pitaj();

        $this->assertSame('Da tražite pismo, rok je 15. novembra 2026.', $odgovor->tekst);
        $this->assertFalse($odgovor->jeIzSablona(), (string) $odgovor->razlogSablona);
        $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $odgovor->tekst));
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

    /** @return array<string, array{0: string}> */
    public static function odgovoriKojiKazuDaNemaPodatka(): array
    {
        return [
            'prvo lice' => ['Nemam podatak.'],
            'drugo lice' => ['Nemaš podatak.'],
            'treće lice' => ['Nema podatka o tome.'],
            'množina prvo lice' => ['Nemamo podatak o tome.'],
            'množina drugo lice' => ['Nemate podataka.'],
            'množina treće lice' => ['Nemaju podatke o tome.'],
            'tekst posle rečenice' => ['Nemaš podatak. Prilike i vodiči nisu sadržali informacije o korišćenju CV-a.'],
            'nema podataka o' => ['Nema podataka o čemu Niš ima. Priloge se odnose na radne ponude u Nišu.'],
            'razdvojen oblik' => ['Ni maš podatak. Prilike nisu sadržale specifične informacije.'],
            'velika slova' => ['NEMAM PODATAK'],
            'između reči' => ['Nemam nikakav podatak o tome.'],
            'informacije' => ['Nemam informacija o tome.'],
            'ćirilica' => ['Немам податак о томе.'],
            'bez dijakritika' => ['Nemas podatak.'],
        ];
    }

    // Merenje iz paketa 21: u 17 od 59 odgovora (18 kad se broji i „Ni maš") tekst je tvrdio da nema podatka, a kartice su stajale ispod.
    #[Test]
    #[DataProvider('odgovoriKojiKazuDaNemaPodatka')]
    public function odgovor_koji_kaze_da_nema_podatka_dok_zapisi_postoje_menja_sablon(string $odgovorModela): void
    {
        $this->model($odgovorModela);

        $odgovor = $this->pitaj();

        $this->assertSame('odgovor kaže da nema podatka', $odgovor->razlogSablona);
        $this->assertStringStartsWith(Pomocnik::SABLON_NASLOV, $odgovor->tekst);
        $this->assertStringContainsString('Radnik u skladištu', $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);
        $this->assertFalse($odgovor->nemaPodatak);
    }

    /** @return array<string, array{0: string}> */
    public static function odgovoriKojiNeKazuDaNemaPodatka(): array
    {
        return [
            'posao postoji' => ['U Nišu postoji posao radnika u skladištu.'],
            'imam podatak' => ['Imam podatak o poslu u Nišu.'],
            'podatak postoji' => ['Podatak o roku je naveden: 15. novembra 2026.'],
            'nema drugih' => ['Nema drugih poslova u Nišu, samo ovaj u skladištu.'],
            'nema roka' => ['Posao u skladištu, prijave stalno otvorene.'],
            'nema roka ali podatak postoji' => ['Nema roka, ali podatak o poslu u skladištu postoji.'],
            // Granica rečenice i zareza se čuva: „nema" i „podatak" iz različitih delova nisu „nema podatka".
            'nema roka pa nova rečenica' => ['Ova prilika nema rok. Informacije o prijavi su na sajtu organizacije.'],
            'nema roka pa zarez' => ['Nema roka, podatak o poslu u skladištu postoji.'],
            'nema ograničenja pa podaci' => ['Nema ograničenja. Podatke možete poslati poštom.'],
            'reč koja samo sadrži nema' => ['Firma Renema daje podatak o poslu u Nišu.'],
            'nemam vremena' => ['Nemam vremena za duge opise, ali posao u Nišu postoji.'],
            'nema u drugim gradovima' => ['U drugim gradovima nema ništa osim posla u Nišu.'],
        ];
    }

    // Ogledalo: odgovor koji ne tvrdi da podatka nema ostaje, i to uz isti zapis.
    #[Test]
    #[DataProvider('odgovoriKojiNeKazuDaNemaPodatka')]
    public function odgovor_koji_ne_kaze_da_nema_podatka_ostaje(string $odgovorModela): void
    {
        $this->model($odgovorModela);

        $odgovor = $this->pitaj();

        $this->assertNull($odgovor->razlogSablona);
        $this->assertSame($odgovorModela, $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);
    }

    // Svaka vrsta zapisa za sebe otvara proveru: uslov „postoje vodiči" ne sme da otpadne a da nijedan test ne pocrveni.
    #[Test]
    public function provera_odbija_nemam_podatak_i_kad_postoji_samo_vodic(): void
    {
        $provera = app(ProveraOdgovora::class);
        $vodic = Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV']);

        $this->assertSame('odgovor kaže da nema podatka', $provera->razlogOdbijanja('Nemam podatak.', new Collection, new Collection([$vodic])));
        $this->assertNull($provera->razlogOdbijanja('Vodič je ispod.', new Collection, new Collection([$vodic])));
    }

    // Ogledalo: bez zapisa „nemam podatak" nije tvrdnja protiv zapisa, pa ga provera ne odbija (a Pomocnik ga ni ne pita).
    #[Test]
    public function provera_ne_odbija_nemam_podatak_kad_zapisa_nema(): void
    {
        $provera = app(ProveraOdgovora::class);

        $this->assertNull($provera->razlogOdbijanja('Nemam podatak.', new Collection, new Collection));
        $this->assertSame('odgovor kaže da nema podatka', $provera->razlogOdbijanja('Nemam podatak.', new Collection([$this->prilika]), new Collection));
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
