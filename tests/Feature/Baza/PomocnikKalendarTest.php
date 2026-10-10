<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\ProveraOdgovora;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 45: pomoćnik zna kalendar. „Kada se otvara Dositeja?" vraća stavku kalendara kao izvor (kartica „Kalendar"); ista pravila provere
// odgovora, a model ne sme da napiše budući datum ni da predvidi šta će biti otvoreno.
#[Group('baza')]
class PomocnikKalendarTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private const PITANJE = 'Kada se otvara Dositeja?';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 9, 12, 0, 0, 'Europe/Belgrade'));
        // Adresa „localhost" nema domen, pa je naša strana vidljiva proveri linkova tek uz pravu adresu sajta.
        app('url')->forceRootUrl('https://nas-sajt.rs');
        $this->postaviModel();
    }

    /** @param  array<string, mixed>  $dopuna */
    private function stavka(array $dopuna = []): KalendarStavka
    {
        return KalendarStavka::factory()->objavljena()->create([...[
            'naziv' => 'Stipendija Dositeja', 'ko_daje' => 'Fond za mlade talente', 'otvoreno_od' => '2025-12-22', 'otvoreno_do' => '2026-01-26',
            'link_izvora' => 'https://fond.rs/dositeja', 'napomena' => null,
        ], ...$dopuna]);
    }

    /** @var array<string, mixed> */
    private array $forma = ['kljucne_reci' => ['Dositeja']];

    private string $odgovorModela = 'Dositeja je prošli put bila otvorena do 26. januara 2026.';

    // Formular (format json) i tekst odgovora se čitaju pri svakom pozivu: ponovljen Http::fake se ne menja, pa test ne sme da ga ponavlja.
    private function postaviModel(): void
    {
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode([...['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], ...$this->forma], JSON_UNESCAPED_UNICODE)]])
                : Http::response(['message' => ['content' => $this->odgovorModela]]);
        }]);
    }

    /** @param  array<string, mixed>|null  $forma */
    private function model(?string $odgovor = null, ?array $forma = null): void
    {
        $this->odgovorModela = $odgovor ?? $this->odgovorModela;
        $this->forma = $forma ?? $this->forma;
    }

    private function pretrazi(string $pitanje = self::PITANJE): RezultatPretrage
    {
        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    /** @return list<string> */
    private function naslovi(RezultatPretrage $rezultat): array
    {
        return $rezultat->kalendar->pluck('naziv')->all();
    }

    #[Test]
    public function pitanje_o_stavci_nalazi_stavku_kalendara(): void
    {
        $stavka = $this->stavka();
        $this->stavka(['naziv' => 'Nagrade za srednjoškolce']);
        $this->model();

        $rezultat = $this->pretrazi();

        $this->assertSame([$stavka->id], $rezultat->kalendar->pluck('id')->all());
        $this->assertFalse($rezultat->nemaPodatak());
        $this->assertSame([], $rezultat->zapisi->all());
    }

    // Ogledala: nacrt, stavka čiji kraj nije prošao, pitanje bez ključnih reči i reč koje nema u stavci ne daju kalendar.
    #[Test]
    public function nacrt_buduca_stavka_i_pitanje_bez_reci_ne_daju_kalendar(): void
    {
        $this->stavka(['naziv' => 'Dositeja nacrt', 'status' => 'nacrt']);
        $this->stavka(['naziv' => 'Dositeja danas', 'otvoreno_do' => '2026-10-09']);
        $this->stavka(['naziv' => 'Dositeja sutra', 'otvoreno_do' => '2026-10-10']);

        $this->model();
        $this->assertSame([], $this->naslovi($this->pretrazi()));

        $this->stavka();
        $this->assertSame(['Stipendija Dositeja'], $this->naslovi($this->pretrazi()));

        // Pitanje bez ključnih reči ne izlistava kalendar, a nepoznata reč ne nalazi ništa.
        $this->model(forma: ['vrsta' => 'stipendija', 'kljucne_reci' => []]);
        $this->assertSame([], $this->naslovi($this->pretrazi('Ima li stipendija?')));
        $this->model(forma: ['kljucne_reci' => ['Zlatni']]);
        $this->assertSame([], $this->naslovi($this->pretrazi('Kada se otvara Zlatni?')));
    }

    // Model reč koja nije grupa često stavi u „kome" umesto u ključne reči („program za roditelje"): i ona mora da piše u stavci,
    // inače „program" sam pogađa svaku stavku. Ogledalo: stavka koja reč ima ostaje.
    #[Test]
    public function kome_koje_nije_grupa_se_trazi_u_stavci_kao_i_kljucne_reci(): void
    {
        $this->stavka(['naziv' => 'Program finansijske podrške ŠTIT u zajednici', 'napomena' => null]);
        $this->model(forma: ['kome' => 'roditelje', 'kljucne_reci' => ['program']]);

        $this->assertSame([], $this->naslovi($this->pretrazi('program za roditelje')));

        $this->stavka(['naziv' => 'Program za roditelje u zajednici']);
        $this->assertSame(['Program za roditelje u zajednici'], $this->naslovi($this->pretrazi('program za roditelje')));
    }

    // Sama reč „kome" ne otvara kalendar: „mlade" piše u „Fond za mlade talente", a pitanje nije o toj stavci.
    #[Test]
    public function samo_kome_bez_druge_reci_ne_izlistava_kalendar(): void
    {
        $this->stavka(['ko_daje' => 'Fond za mlade talente']);

        foreach ([['vrsta' => 'praksa', 'kome' => 'mlade', 'kljucne_reci' => ['praksu']], ['kome' => 'mlade', 'kljucne_reci' => []]] as $forma) {
            $this->model(forma: $forma);

            $this->assertSame([], $this->naslovi($this->pretrazi('Tražim praksu za mlade')), json_encode($forma));
        }

        // Ogledalo: kad pitanje ima i pravu reč, a „kome" piše u stavci, stavka se nalazi.
        $this->model(forma: ['kome' => 'mlade', 'kljucne_reci' => ['Dositeja']]);
        $this->assertSame(['Stipendija Dositeja'], $this->naslovi($this->pretrazi('Kada se otvara Dositeja za mlade?')));
    }

    // Najviše tri stavke, najnovije prve; ogledalo: kad ih je tri ili manje, ulaze sve.
    #[Test]
    public function kalendar_vraca_najvise_tri_stavke_najnovije_prve(): void
    {
        foreach ([1, 2, 3, 4, 5] as $mesec) {
            $this->stavka(['naziv' => 'Dositeja '.$mesec, 'otvoreno_do' => sprintf('2026-0%d-15', $mesec)]);
        }
        $this->model();

        $this->assertSame(['Dositeja 5', 'Dositeja 4', 'Dositeja 3'], $this->naslovi($this->pretrazi()));

        KalendarStavka::query()->whereIn('naziv', ['Dositeja 5', 'Dositeja 4', 'Dositeja 3'])->delete();

        $this->assertSame(['Dositeja 2', 'Dositeja 1'], $this->naslovi($this->pretrazi()));
    }

    #[Test]
    public function reci_se_traze_i_u_napomeni_i_kod_ko_daje(): void
    {
        $this->stavka(['naziv' => 'Prvi', 'napomena' => 'Prijave su išle preko eUprave.', 'ko_daje' => 'Fondacija X']);
        $this->stavka(['naziv' => 'Drugi', 'ko_daje' => 'Fond za mlade talente']);

        $this->model(forma: ['kljucne_reci' => ['eUprave']]);
        $this->assertSame(['Prvi'], $this->naslovi($this->pretrazi('Šta je bilo preko eUprave?')));
        $this->model(forma: ['kljucne_reci' => ['talente']]);
        $this->assertSame(['Drugi'], $this->naslovi($this->pretrazi('Šta daje Fond za mlade talente?')));
    }

    // Grupa iz pitanja sužava samo stavke koje grupe imaju; stavka bez grupa važi za svakoga.
    #[Test]
    public function grupa_iz_pitanja_suzava_samo_stavke_sa_grupama(): void
    {
        $this->stavka(['naziv' => 'Dositeja Za studente', 'grupe' => ['studenti']]);
        $this->stavka(['naziv' => 'Dositeja Za osnovce', 'grupe' => ['osnovci']]);
        $this->stavka(['naziv' => 'Dositeja Bez grupa', 'grupe' => null]);
        $this->model(forma: ['kljucne_reci' => ['Dositeja']]);

        $this->assertEqualsCanonicalizing(['Dositeja Za studente', 'Dositeja Bez grupa'], $this->naslovi($this->pretrazi('Kada je Dositeja bila za studente?')));
        $this->assertEqualsCanonicalizing(['Dositeja Za osnovce', 'Dositeja Bez grupa'], $this->naslovi($this->pretrazi('Kada je Dositeja bila za osnovce?')));
        // Bez grupe u pitanju ulaze sve tri.
        $this->assertCount(3, $this->naslovi($this->pretrazi('Kada je bila Dositeja?')));
    }

    // Reč koja samo kaže vrstu (stipendija) ili grupu (studente) nije razlog da se izlista kalendar; ostale reči jesu, i kad liče na vrstu.
    #[Test]
    public function reci_vrste_i_grupe_same_ne_izlistavaju_kalendar_a_ostale_se_ne_gube(): void
    {
        $this->stavka(['naziv' => 'Stipendija za studente: program ŠTIT u zajednici', 'grupe' => ['studenti']]);

        foreach ([['kljucne_reci' => ['stipendija']], ['kljucne_reci' => ['studente']], ['kljucne_reci' => ['stipendija', 'studente']]] as $forma) {
            $this->forma = $forma;

            $this->assertSame([], $this->naslovi($this->pretrazi('Kada se otvara stipendija za studente?')), json_encode($forma));
        }

        // „ŠTIT" počinje kao „stipendija", pa ga formular uzima za ponavljanje vrste; kalendar ga ipak nalazi.
        $this->forma = ['vrsta' => 'stipendija', 'kljucne_reci' => ['ŠTIT']];
        $this->assertSame(['Stipendija za studente: program ŠTIT u zajednici'], $this->naslovi($this->pretrazi('Kada se otvara ŠTIT?')));
    }

    #[Test]
    public function odgovor_nosi_karticu_kalendara_sa_prosli_put_i_zvanicnim_izvorom(): void
    {
        $this->stavka();
        $this->model();

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertFalse($odgovor->jeIzSablona());
        $this->assertSame('Dositeja je prošli put bila otvorena do 26. januara 2026.', $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);

        $izvor = $odgovor->izvori[0];

        $this->assertTrue($izvor->jeKalendar);
        $this->assertFalse($izvor->jeVodic);
        $this->assertFalse($izvor->jeOrganizacija);
        $this->assertSame('Stipendija Dositeja', $izvor->naslov);
        $this->assertSame('Prošli put: od 22.12.2025. do 26.01.2026.', $izvor->rok);
        $this->assertSame(route('kalendar.index'), $izvor->adresa);
        $this->assertSame('https://fond.rs/dositeja', $izvor->zvanicniLink);
        $this->assertSame('fond.rs', $izvor->zvanicniNaziv);
    }

    // Link koji nije veb adresa (stigao mimo admina) ne ide u zvanični izvor.
    #[Test]
    public function los_link_stavke_se_ne_prikazuje_kao_zvanicni_izvor(): void
    {
        $stavka = $this->stavka();
        KalendarStavka::query()->whereKey($stavka->getKey())->update(['link_izvora' => 'javascript:alert(1)']);
        $this->model();

        $izvor = app(Pomocnik::class)->odgovori(self::PITANJE)->izvori[0];

        $this->assertNull($izvor->zvanicniLink);
        $this->assertNull($izvor->zvanicniNaziv);
    }

    // Odgovor sa budućim datumom (izmišljenim) daje šablon iz zapisa, sa razlogom.
    /** @return array<string, array{0: string}> */
    public static function izmisljeniBuduciDatumi(): array
    {
        return [
            'datum sa godinom' => ['Dositeja se otvara 15. januara 2027.'],
            'brojevi' => ['Sledeći put prijave su do 15.01.2027.'],
            'iso' => ['Prijave će trajati do 2027-01-15.'],
            'prošla godina ali drugi dan' => ['Prošli put je bila otvorena do 27. januara 2026.'],
            'datum kog nema bez godine' => ['Prošli put je bila otvorena do 27. januara.'],
        ];
    }

    #[Test]
    #[DataProvider('izmisljeniBuduciDatumi')]
    public function odgovor_sa_izmisljenim_datumom_daje_sablon(string $tekst): void
    {
        $this->stavka();
        $this->model($tekst);

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertTrue($odgovor->jeIzSablona(), $tekst);
        $this->assertSame('datum kog nema u zapisima', $odgovor->razlogSablona);
        $this->assertStringStartsWith(Pomocnik::SABLON_NASLOV_KALENDAR, $odgovor->tekst);
        $this->assertStringContainsString('• Stipendija Dositeja — Prošli put: od 22.12.2025. do 26.01.2026.', $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);
    }

    // Ogledalo: datum iz kalendara (u svim oblicima) prolazi.
    /** @return array<string, array{0: string}> */
    public static function datumiIzKalendara(): array
    {
        return [
            'tekstom' => ['Dositeja je prošli put bila otvorena do 26. januara 2026.'],
            'brojevima' => ['Prošli put je bila otvorena do 26.01.2026.'],
            'početak i kraj' => ['Bila je otvorena od 22. decembra 2025. do 26. januara 2026.'],
            'bez godine' => ['Prošli put je bila otvorena do 26. januara.'],
            'bez datuma' => ['Dositeja je bila otvorena prošli put, a pojedinosti su u kalendaru.'],
        ];
    }

    #[Test]
    #[DataProvider('datumiIzKalendara')]
    public function odgovor_sa_datumom_iz_kalendara_prolazi(string $tekst): void
    {
        $this->stavka();
        $this->model($tekst);

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertFalse($odgovor->jeIzSablona(), $tekst.' | '.$odgovor->razlogSablona);
        $this->assertSame($tekst, $odgovor->tekst);
    }

    // Model ne sme da predvidi šta će biti: „otvoriće se", „otvara se", „biće otvoren", „očekuje se"…
    /** @return array<string, array{0: string}> */
    public static function predvidjanja(): array
    {
        return [
            'otvoriće se' => ['Dositeja će se otvoriti u januaru.'],
            'otvoriće bez „će se"' => ['Otvoriće se opet u januaru.'],
            'otvara se' => ['Dositeja se otvara u januaru.'],
            'biće otvorena' => ['Biće otvorena u januaru.'],
            'očekuje se' => ['Očekuje se ponovo u januaru.'],
            'sledeće godine' => ['Sledeće godine ponovo, u januaru.'],
            'najavljen' => ['Najavljen je novi konkurs.'],
        ];
    }

    #[Test]
    #[DataProvider('predvidjanja')]
    public function odgovor_koji_predvidja_buducnost_daje_sablon(string $tekst): void
    {
        $this->stavka();
        $this->model($tekst);

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertTrue($odgovor->jeIzSablona(), $tekst);
        $this->assertSame('odgovor predviđa budućnost', $odgovor->razlogSablona);
    }

    // Datum posle današnjeg dana koji stoji u napomeni stavke se ne prenosi: kalendar ne predviđa ni preko napomene.
    #[Test]
    public function buduci_datum_iz_napomene_se_ne_prenosi_a_rok_prilike_prolazi(): void
    {
        $this->stavka(['napomena' => 'Sledeća runda 15. januara 2027.']);
        $this->model('Napomena kaže 15. januara 2027.');

        $this->assertSame('odgovor predviđa budućnost', app(Pomocnik::class)->odgovori(self::PITANJE)->razlogSablona);

        // Ogledalo: isti budući datum je dozvoljen kad je to rok prilike koja je u odgovoru (prilike imaju buduće rokove).
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Dositeja ove godine', 'rok' => '2027-01-15', 'vrsta' => 'stipendija', 'kratak_opis' => 'Stipendija.', 'opis' => 'Stipendija.']);
        $this->model('Dositeja je prošli put bila otvorena do 26. januara 2026, a sada je rok 15. januara 2027.');

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertFalse($odgovor->jeIzSablona(), (string) $odgovor->razlogSablona);
        $this->assertSame(['Dositeja ove godine', 'Stipendija Dositeja'], array_map(fn ($i) => $i->naslov, $odgovor->izvori));
        $this->assertSame([false, true], array_map(fn ($i) => $i->jeKalendar, $odgovor->izvori));
        $this->assertSame(route('prilike.show', $prilika->slug), $odgovor->izvori[0]->adresa);
    }

    // Datum iz napomene na današnji dan nije budućnost (prolazi), a sutrašnji jeste (šablon).
    #[Test]
    public function datum_iz_napomene_danas_prolazi_a_sutra_ne(): void
    {
        $stavka = $this->stavka(['napomena' => 'Prijave su trajale do 9. oktobra 2026.']);
        $this->model('Prijave su trajale do 9. oktobra 2026.');

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);
        $this->assertFalse($odgovor->jeIzSablona(), (string) $odgovor->razlogSablona);

        $stavka->update(['napomena' => 'Prijave su trajale do 10. oktobra 2026.']);
        $this->model('Prijave su trajale do 10. oktobra 2026.');

        $this->assertSame('odgovor predviđa budućnost', app(Pomocnik::class)->odgovori(self::PITANJE)->razlogSablona);
    }

    // Provera kao za ostale zapise: „nemam podatak" uz stavku protivreči kartici, a tuđ link ne prolazi.
    #[Test]
    public function ista_pravila_provere_vaze_i_za_kalendar(): void
    {
        $stavka = $this->stavka();
        $provera = app(ProveraOdgovora::class);
        $kalendar = new Collection([$stavka]);

        $this->assertSame('odgovor kaže da nema podatka', $provera->razlogOdbijanja('Nemam podatak.', new Collection, new Collection, new Collection, $kalendar));
        $this->assertNull($provera->razlogOdbijanja('Nemam podatak.', new Collection, new Collection, new Collection, new Collection));
        $this->assertSame('link kog nema u zapisima', $provera->razlogOdbijanja('Prijave su bile na sajtu tudji.rs.', new Collection, new Collection, new Collection, $kalendar));
        $this->assertNull($provera->razlogOdbijanja('Prijave su bile na fond.rs.', new Collection, new Collection, new Collection, $kalendar));
        $this->assertSame('prazan odgovor', $provera->razlogOdbijanja('', new Collection, new Collection, new Collection, $kalendar));
        $this->assertSame('predug odgovor', $provera->razlogOdbijanja(str_repeat('a', ProveraOdgovora::NAJVISE_ZNAKOVA + 1), new Collection, new Collection, new Collection, $kalendar));
    }

    // Ogledalo: bez kalendara u odgovoru „otvara se" nije zabranjeno (prilike koje su sada otvorene).
    #[Test]
    public function predvidjanje_se_proverava_samo_kad_je_u_odgovoru_kalendar(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-11-15']);
        $provera = app(ProveraOdgovora::class);

        $this->assertNull($provera->razlogOdbijanja('Prijava se otvara odmah.', new Collection([$prilika]), new Collection, new Collection, new Collection));
        $this->assertSame('odgovor predviđa budućnost', $provera->razlogOdbijanja('Prijava se otvara odmah.', new Collection([$prilika]), new Collection, new Collection, new Collection([$this->stavka()])));
    }

    #[Test]
    public function model_dobija_kalendar_i_uputstvo_da_ne_predvidja(): void
    {
        $this->stavka(['grupe' => ['studenti'], 'napomena' => 'Prijave su išle preko eUprave.']);
        $this->model();

        app(Pomocnik::class)->odgovori(self::PITANJE);

        Http::assertSent(function (Request $zahtev) {
            if ($zahtev->url() !== self::OLLAMA || array_key_exists('format', $zahtev->data())) {
                return false;
            }

            $poruke = $zahtev->data()['messages'];

            return str_contains($poruke[0]['content'], 'samo iz priloženih prilika, vodiča i organizacija')
                && str_contains($poruke[0]['content'], 'Kalendar kaže samo šta je bilo prošli put: ne predviđaj kada će se nešto otvoriti')
                && str_contains($poruke[0]['content'], 'Pitanje „kada se otvara" znači „kada je bilo otvoreno prošli put"')
                && str_contains($poruke[1]['content'], "Kalendar:\nNaziv: Stipendija Dositeja; Ko daje: Fond za mlade talente; Kome je namenjeno: Studenti; Prošli put: od 22.12.2025. do 26.01.2026.; Napomena: Prijave su išle preko eUprave.");
        });
    }

    // Ogledalo: bez kalendara u kontekstu uputstvo je isto kao ranije, bez reči o kalendaru (izmereno: dodatak menja odgovore na ostala pitanja).
    #[Test]
    public function bez_kalendara_uputstvo_ne_pominje_kalendar(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => 'posao', 'rok' => '2026-11-15', 'kratak_opis' => 'Puno radno vreme.', 'opis' => 'Rad u skladištu.']);
        $this->model(forma: ['vrsta' => 'posao', 'kljucne_reci' => []]);
        $this->odgovorModela = 'Ima posla u skladištu.';

        app(Pomocnik::class)->odgovori('Ima li posla?');

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA
            && ! array_key_exists('format', $zahtev->data())
            && str_contains($zahtev->data()['messages'][0]['content'], 'samo iz priloženih prilika, vodiča i organizacija')
            && ! str_contains($zahtev->data()['messages'][0]['content'], 'Kalendar')
            && ! str_contains($zahtev->data()['messages'][1]['content'], 'Kalendar:'));
    }

    // Ceo put kroz stranu: kartica „Kalendar" sa „Prošli put", vezom na kalendar i zvaničnim izvorom.
    #[Test]
    public function strana_pomocnika_crta_karticu_kalendar(): void
    {
        $this->stavka();
        $this->model();

        $html = (string) $this->post(route('pomocnik.pitaj'), ['pitanje' => self::PITANJE])->assertOk()->getContent();

        $this->assertStringContainsString('<span class="oznaka">Kalendar</span>', $html);
        $this->assertStringContainsString('<h3><a href="'.route('kalendar.index').'">Stipendija Dositeja</a></h3>', $html);
        $this->assertStringContainsString('Prošli put: od 22.12.2025. do 26.01.2026.', $this->vidljivTekst($html));
        $this->assertStringContainsString('<a href="https://fond.rs/dositeja" rel="noopener noreferrer nofollow" target="_blank">fond.rs</a>', $html);
    }

    // Ogledalo: kartica prilike na istoj strani nema oznaku „Kalendar".
    #[Test]
    public function kartica_prilike_nema_oznaku_kalendar(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Dositeja ove godine', 'vrsta' => 'stipendija', 'rok' => '2026-12-01', 'kratak_opis' => 'Stipendija.', 'opis' => 'Stipendija.']);
        $this->model('Dositeja ove godine je otvorena do 1. decembra 2026.');

        $html = (string) $this->post(route('pomocnik.pitaj'), ['pitanje' => self::PITANJE])->assertOk()->getContent();

        $this->assertStringContainsString('Dositeja ove godine', $html);
        $this->assertStringNotContainsString('<span class="oznaka">Kalendar</span>', $html);
    }

    // Prilike, vodiči i organizacije se i dalje nalaze uz kalendar (isti pitanje, više vrsta zapisa).
    #[Test]
    public function kalendar_stoji_uz_prilike_u_istom_odgovoru(): void
    {
        $this->stavka();
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Dositeja 2027', 'vrsta' => 'stipendija', 'rok' => '2027-01-25', 'kratak_opis' => 'Stipendija.', 'opis' => 'Stipendija.']);
        $this->model('Stipendija Dositeja je prošli put bila otvorena do 26. januara 2026, a sada je rok 25. januara 2027.');

        $odgovor = app(Pomocnik::class)->odgovori(self::PITANJE);

        $this->assertFalse($odgovor->jeIzSablona(), (string) $odgovor->razlogSablona);
        $this->assertSame(['Dositeja 2027', 'Stipendija Dositeja'], array_map(fn ($i) => $i->naslov, $odgovor->izvori));
        $this->assertSame([false, true], array_map(fn ($i) => $i->jeKalendar, $odgovor->izvori));
        $this->assertSame(route('prilike.show', $prilika->slug), $odgovor->izvori[0]->adresa);
    }

    #[Test]
    public function nema_podatka_kad_nema_ni_prilika_ni_kalendara(): void
    {
        $this->model(forma: ['kljucne_reci' => ['Zlatni']]);

        $odgovor = app(Pomocnik::class)->odgovori('Kada se otvara Zlatni?');

        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertSame([], $odgovor->izvori);
        $this->assertSame(Pomocnik::NEMAM_PODATAK, $odgovor->tekst);
    }
}
