<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 85 (Ivan, 30.10.2026.: „zašto ja, zašto ne AI"): dnevna provera javnih prilika prema strani izvora. Vraća u nacrt zatvorenu priliku i onu čiji rok ne piše na strani, upisuje
// nov rok koji strana sama piše; sve uz rečenicu sa strane i bez modela. Strane su pravi snimci (Ministarstvo prosvete, NSZ, Youth.rs, Erasmus+), skraćeni na članak.
#[Group('baza')]
class PrilikeProveraTest extends BazaTestCase
{
    use RefreshDatabase;

    private const STRANA = 'https://a.test/poziv-1';

    protected function setUp(): void
    {
        parent::setUp();

        // Nijedan zahtev ne ide na pravi internet, ni na model: što nije lažirano, baca izuzetak.
        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 30, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function snimak(string $ime): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/stranice/'.$ime));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function javna(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => 'Poziv', 'vrsta' => VrstaPrilike::Konkurs, 'rok' => '2026-11-10', 'naziv_izvora' => 'Izvor A', 'link_izvora' => self::STRANA,
        ], ...$dopuna]);
    }

    // Lažiranje se pamti i ranije ima prednost, pa se pre svakog novog Http zamenjuje čistim (i dalje bez pravih zahteva).
    private function stranaJe(string $telo, int $status = 200, string $adresa = self::STRANA): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([$adresa => Http::response($telo, $status, ['Content-Type' => 'text/html; charset=UTF-8'])]);
    }

    private function proveri(string $ispis = 'Provereno'): void
    {
        $this->assertSame(0, Artisan::call('prilike:provera'));
        $this->assertStringContainsString($ispis, Artisan::output());
    }

    /** @param  array<string, mixed>  $predlog */
    private function stranaSaRecenicom(string $recenica, array $predlog = []): string
    {
        return '<html><body><article><h1>Poziv</h1><p>'.$recenica.'</p></article></body></html>';
    }

    #[Test]
    public function zatvoren_poziv_se_vraca_u_nacrt_sa_razlogom_koji_se_vidi_u_adminu(): void
    {
        $prilika = $this->javna(['rok' => '2026-11-15']);
        $this->stranaJe($this->snimak('prosveta-konkurs-istekao.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $nacrt = $prilika->fresh();
        $this->assertSame(StatusPrilike::Nacrt, $nacrt->status);
        $this->assertSame('strana piše da je poziv zatvoren', data_get($nacrt->predlog, 'odbijeno'));
        $this->assertStringContainsString('Konkurs je istekao 2.09.2026.', (string) data_get($nacrt->predlog, 'provera.citat'));
        $this->assertSame('2026-10-30', data_get($nacrt->predlog, 'provera.datum'));
        $this->assertContains('strana piše da je poziv zatvoren', Prilika::razloziNacrta());
        $this->assertSame(0, Prilika::query()->javne()->count());
    }

    // Ogledalo: strana poziva koji je otvoren (rok na strani je isti kao u bazi) ne menja ništa.
    #[Test]
    public function otvoren_poziv_ostaje_javan_i_neizmenjen(): void
    {
        $prilika = $this->javna(['predlog' => ['pravilo' => 5, 'model' => 'qwen2.5:7b']]);
        $pre = $prilika->fresh()->toArray();
        $this->stranaJe($this->snimak('erasmus-mentorski-program.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame($pre, $prilika->fresh()->toArray());
    }

    /** @return array<string, array{string}> */
    public static function recenice_o_zatvaranju(): array
    {
        return [
            'konkurs je zatvoren' => ['Konkurs je zatvoren.'],
            'prijave su zavrsene' => ['Prijave su završene 5. novembra.'],
            'rok je istekao' => ['Rok za prijavu je istekao.'],
            'poziv je zavrsen' => ['Ovaj javni poziv je završen.'],
        ];
    }

    #[Test]
    #[DataProvider('recenice_o_zatvaranju')]
    public function rec_o_zatvaranju_uz_predmet_poziva_vraca_u_nacrt(string $recenica): void
    {
        $prilika = $this->javna();
        $this->stranaJe($this->stranaSaRecenicom($recenica.' Rok za prijavu je 10. novembra 2026.'));

        $this->proveri();

        $this->assertSame(StatusPrilike::Nacrt, $prilika->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function recenice_koje_nisu_zatvaranje(): array
    {
        return [
            'nije zatvoren' => ['Konkurs još nije zatvoren.'],
            'prijava je zavrsena slanjem' => ['Prijava je završena kad pošaljete formular.'],
            'zavrsena skola' => ['Konkurs je namenjen osobama sa završenom osnovnom školom.'],
            'drugi predmet' => ['Radionica je završena u petak.'],
        ];
    }

    // Ogledalo: reč „završen" ili „zatvoren" bez predmeta poziva (ili uz „nije") nije dokaz da je poziv zatvoren.
    #[Test]
    #[DataProvider('recenice_koje_nisu_zatvaranje')]
    public function recenica_koja_nije_o_zatvaranju_poziva_ne_vraca_u_nacrt(string $recenica): void
    {
        $prilika = $this->javna();
        $this->stranaJe($this->stranaSaRecenicom($recenica.' Rok za prijavu je 10. novembra 2026.'));

        $this->proveri();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
    }

    #[Test]
    public function rok_koji_vise_ne_pise_na_strani_vraca_u_nacrt(): void
    {
        $prilika = $this->javna(['rok' => '2026-11-20']);
        $this->stranaJe($this->snimak('erasmus-mentorski-program.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $this->assertSame(StatusPrilike::Nacrt, $prilika->fresh()->status);
        $this->assertSame('rok ne piše u tekstu strane', data_get($prilika->fresh()->predlog, 'odbijeno'));
        $this->assertSame('2026-11-20', $prilika->fresh()->rok?->toDateString());
    }

    #[Test]
    public function strana_koja_sama_pise_kasniji_rok_uz_produzenje_upisuje_nov_rok(): void
    {
        $prilika = $this->javna(['rok' => '2026-11-02', 'predlog' => ['pravilo' => 5, 'rok' => '2026-11-02', 'citat' => 'Rok je 2. novembra 2026.']]);
        $this->stranaJe($this->snimak('prosveta-produzen-rok.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 1.');

        $izmenjena = $prilika->fresh();
        $this->assertSame(StatusPrilike::Objavljeno, $izmenjena->status);
        $this->assertSame('2026-11-15', $izmenjena->rok?->toDateString());
        $this->assertSame('2026-11-02', data_get($izmenjena->predlog, 'provera.rok_pre'));
        $this->assertSame('2026-11-15', data_get($izmenjena->predlog, 'provera.rok_posle'));
        $this->assertStringContainsString('produžava se do 15. novembra 2026', (string) data_get($izmenjena->predlog, 'provera.citat'));
        $this->assertSame(1, Prilika::query()->javne()->count());
    }

    // Ogledalo: kad je rok iz baze već onaj sa strane, ili je kasniji od onog sa strane, ništa se ne upisuje i ne vraća.
    #[Test]
    public function rok_koji_je_vec_na_strani_ostaje_neizmenjen(): void
    {
        $prilika = $this->javna(['rok' => '2026-11-15']);
        $this->stranaJe($this->snimak('prosveta-produzen-rok.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame('2026-11-15', $prilika->fresh()->rok?->toDateString());
        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
    }

    // Ogledalo: drugi, kasniji datum uz drugu reč roka („rok za dostavljanje") nije produženje roka za prijavu, dok god strana ne kaže „produžen".
    #[Test]
    public function kasniji_datum_bez_reci_produzen_ne_menja_rok_koji_pise_na_strani(): void
    {
        $prilika = $this->javna();
        $this->stranaJe($this->stranaSaRecenicom('Rok za prijavu je 10. novembra 2026. Rok za dostavljanje dokumentacije je 20. novembra 2026.'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame('2026-11-10', $prilika->fresh()->rok?->toDateString());
    }

    // Ogledalo: dva kasnija roka na strani, a onog iz baze nema: nije jasno koji važi, pa je nacrt, ne nagađanje.
    #[Test]
    public function dva_kasnija_roka_bez_roka_iz_baze_vracaju_u_nacrt_umesto_da_biraju(): void
    {
        $prilika = $this->javna(['rok' => '2026-11-05']);
        $this->stranaJe($this->stranaSaRecenicom('Rok za prvu fazu je 10. novembra 2026. Rok za drugu fazu je 20. novembra 2026.'));

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $this->assertSame(StatusPrilike::Nacrt, $prilika->fresh()->status);
        $this->assertSame('2026-11-05', $prilika->fresh()->rok?->toDateString());
    }

    // Ogledalo: kasniji datum bez godine se ne uzima kao rok: godinu ne bira kod.
    #[Test]
    public function kasniji_datum_bez_godine_nije_nov_rok(): void
    {
        $prilika = $this->javna(['rok' => '2026-11-05']);
        $this->stranaJe($this->stranaSaRecenicom('Rok za prijavu je produžen do 20. novembra.'));

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $this->assertSame('2026-11-05', $prilika->fresh()->rok?->toDateString());
    }

    // Posle novog roka zapis se slaže sa njim: rok bez godine koji je pre produženja bio blizu objave sada je dalek, a to nije razlog da se prilika odmah vrati u nacrt.
    #[Test]
    public function nov_rok_se_upisuje_tako_da_ga_sledeci_prolaz_ne_vraca_u_nacrt(): void
    {
        $prilika = $this->javna([
            'rok' => '2026-11-02', 'izvor_objavljen' => '2026-05-10',
            'predlog' => ['pravilo' => 5, 'rok' => '2026-11-02', 'citat' => 'Rok za prijavu je 2. novembra.'],
        ]);
        $this->stranaJe($this->snimak('prosveta-produzen-rok.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 1.');
        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
        $this->assertSame('2026-11-15', $prilika->fresh()->rok?->toDateString());
    }

    #[Test]
    public function poziv_bez_roka_koji_nije_stalno_otvoren_vraca_se_u_nacrt(): void
    {
        $prilika = $this->javna(['rok' => null, 'vrsta' => VrstaPrilike::Drugo]);
        $this->stranaJe($this->snimak('nsz-poziv-bez-roka.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $this->assertSame(StatusPrilike::Nacrt, $prilika->fresh()->status);
        $this->assertSame('rok ne piše u tekstu strane', data_get($prilika->fresh()->predlog, 'odbijeno'));
    }

    // Ogledalo 1: stalno otvorene prijave nemaju rok po dogovoru; ogledalo 2: bez roka, a strana sama piše rok, daje nov rok umesto nacrta.
    #[Test]
    public function stalno_otvorena_prilika_bez_roka_ostaje_a_ona_kojoj_strana_pise_rok_dobija_ga(): void
    {
        $stalna = $this->javna(['rok' => null, 'rok_stalno_otvoren' => true]);
        $this->stranaJe($this->snimak('nsz-poziv-bez-roka.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame(StatusPrilike::Objavljeno, $stalna->fresh()->status);

        $stalna->delete();
        $bezRoka = $this->javna(['rok' => null]);
        $this->stranaJe($this->snimak('prosveta-produzen-rok.html'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 1.');

        $this->assertSame('2026-11-15', $bezRoka->fresh()->rok?->toDateString());
        $this->assertNull(data_get($bezRoka->fresh()->predlog, 'provera.rok_pre'));
    }

    #[Test]
    public function rok_bez_godine_dalek_od_objave_vraca_u_nacrt_i_bez_otvaranja_strane(): void
    {
        $citat = (string) json_decode($this->snimak('youth-recnik-otpora.odgovor-modela.json'), true, flags: JSON_THROW_ON_ERROR)['citat'];
        $recnik = $this->javna([
            'rok' => '2026-12-05', 'izvor_objavljen' => '2025-12-08',
            'predlog' => ['pravilo' => 5, 'rok' => '2026-12-05', 'citat' => $citat, 'odbijeno' => null, 'model' => 'claude-haiku-5-5'],
        ]);

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $this->assertSame(StatusPrilike::Nacrt, $recnik->fresh()->status);
        $this->assertSame('rok bez godine je daleko od datuma objave', data_get($recnik->fresh()->predlog, 'odbijeno'));
        Http::assertNothingSent();
    }

    // Ogledalo: rok bez godine bliži od šest meseci prolazi; rok koji je Ivan posle objave sam promenio se ne meri po starom citatu.
    #[Test]
    public function rok_bez_godine_blizu_objave_i_rok_koji_je_ivan_promenio_ostaju(): void
    {
        $blizu = $this->javna([
            'rok' => '2026-11-20', 'izvor_objavljen' => '2026-10-15',
            'predlog' => ['pravilo' => 5, 'rok' => '2026-11-20', 'citat' => 'Rok za prijavu je 20. novembra.'],
        ]);
        $this->stranaJe($this->stranaSaRecenicom('Rok za prijavu je 20. novembra.'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame(StatusPrilike::Objavljeno, $blizu->fresh()->status);

        $blizu->delete();
        $izmenjena = $this->javna([
            'rok' => '2026-12-05', 'izvor_objavljen' => '2025-12-08',
            'predlog' => ['pravilo' => 5, 'rok' => '2025-12-05', 'citat' => 'Popuni formular najkasnije do 5. decembra.'],
        ]);
        $this->stranaJe($this->stranaSaRecenicom('Rok za prijavu je 5. decembra 2026.'));

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame(StatusPrilike::Objavljeno, $izmenjena->fresh()->status);
    }

    #[Test]
    public function strana_koje_nema_dva_dana_zaredom_vraca_u_nacrt_a_prvi_dan_samo_pamti(): void
    {
        $prilika = $this->javna();
        $this->stranaJe('Nema.', 404);

        $this->proveri('Nije provereno (strana nije stigla ili je prvi put nema): 1.');

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
        $this->assertSame('2026-10-30', data_get($prilika->fresh()->predlog, 'nedostupna_od'));

        // Isti dan drugi put nije „dva dana zaredom".
        $this->proveri('Nije provereno (strana nije stigla ili je prvi put nema): 1.');
        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);

        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
        $this->stranaJe('Nema.', 404);

        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');

        $this->assertSame(StatusPrilike::Nacrt, $prilika->fresh()->status);
        $this->assertSame('strana izvora ne postoji dva dana zaredom', data_get($prilika->fresh()->predlog, 'odbijeno'));
    }

    // Ogledalo: strana koja se vrati posle prvog dana briše belešku, pa nova nestanak počinje od početka; 410 važi kao 404.
    #[Test]
    public function strana_koja_se_vrati_brise_belesku_pa_novi_nestanak_ne_vraca_u_nacrt(): void
    {
        $prilika = $this->javna();
        $this->stranaJe('Nema.', 410);
        $this->proveri();
        $this->assertSame('2026-10-30', data_get($prilika->fresh()->predlog, 'nedostupna_od'));

        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
        $this->stranaJe($this->snimak('erasmus-mentorski-program.html'));
        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');
        $this->assertNull(data_get($prilika->fresh()->predlog, 'nedostupna_od'));

        $this->travelTo(Carbon::create(2026, 11, 1, 12, 0, 0, 'Europe/Belgrade'));
        $this->stranaJe('Nema.', 404);
        $this->proveri();

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
        $this->assertSame('2026-11-01', data_get($prilika->fresh()->predlog, 'nedostupna_od'));
    }

    // Ogledalo: greška servera, zabrana ili strana koja ne odgovara nisu dokaz da strane nema, ni jednom ni dvaput.
    #[Test]
    public function strana_koja_ne_stize_iz_drugog_razloga_nikad_ne_vraca_u_nacrt(): void
    {
        $prilika = $this->javna();

        foreach ([500, 503, 403] as $dan => $status) {
            $this->travelTo(Carbon::create(2026, 10, 30 + $dan, 12, 0, 0, 'Europe/Belgrade'));
            $this->stranaJe('Greška.', $status);
            $this->proveri('Nije provereno (strana nije stigla ili je prvi put nema): 1.');
        }

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->fresh()->status);
        $this->assertNull(data_get($prilika->fresh()->predlog, 'nedostupna_od'));
    }

    #[Test]
    public function infostud_oglasu_se_meri_samo_rok_iz_polja_isteka_i_postojanje_strane(): void
    {
        $adresa = 'https://poslovi.infostud.com/posao/magacioner/probna-firma-doo/760840';
        $strana = str_replace('25.10.2026.', '20.11.2026.', $this->snimak('infostud-oglas.html'));
        $oglas = $this->javna(['vrsta' => VrstaPrilike::Posao, 'rok' => '2026-11-20', 'link_izvora' => $adresa]);
        $this->stranaJe($strana, 200, $adresa);

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');
        $this->assertSame('2026-11-20', $oglas->fresh()->rok?->toDateString());

        // Strana kaže kasniji rok: nov rok.
        $oglas->forceFill(['rok' => '2026-11-12'])->save();
        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 1.');
        $this->assertSame('2026-11-20', $oglas->fresh()->rok?->toDateString());

        // Strana kaže raniji rok od našeg: našeg roka na strani nema, a nov rok se ne dokazuje (samo kasniji): nacrt.
        $oglas->forceFill(['rok' => '2026-12-01'])->save();
        $this->proveri('Provereno 1, vraćeno u nacrt 1, nov rok 0.');
        $this->assertSame(StatusPrilike::Nacrt, $oglas->fresh()->status);
    }

    // Ogledalo: Infostud oglas ne piše „zatvoren" rečima, pa ta reč u njegovom tekstu ne vraća u nacrt; strana bez polja isteka se samo ne proverava.
    #[Test]
    public function infostud_ne_cita_reci_o_zatvaranju_a_bez_polja_roka_ostaje_neprovereno(): void
    {
        $adresa = 'https://poslovi.infostud.com/posao/magacioner/probna-firma-doo/760840';
        $oglas = $this->javna(['vrsta' => VrstaPrilike::Posao, 'rok' => '2026-11-20', 'link_izvora' => $adresa]);
        $this->stranaJe(str_replace(['25.10.2026.', 'Traži se magacioner'], ['20.11.2026.', 'Konkurs je zatvoren. Traži se magacioner'], $this->snimak('infostud-oglas.html')), 200, $adresa);

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');
        $this->assertSame(StatusPrilike::Objavljeno, $oglas->fresh()->status);

        $this->stranaJe('<html><body><main><h1>Magacioner</h1><p>Rok 20.11.2026.</p></main></body></html>', 200, $adresa);
        $this->proveri('Nije provereno (strana nije stigla ili je prvi put nema): 1.');
        $this->assertSame(StatusPrilike::Objavljeno, $oglas->fresh()->status);
    }

    // Ogledalo za sve gore: proveravaju se samo javne prilike (objavljene, rok nije prošao); nacrt, arhivirana i objavljena sa prošlim rokom se ne otvaraju.
    #[Test]
    public function proveravaju_se_samo_javne_prilike(): void
    {
        $this->javna(['link_izvora' => 'https://a.test/javna', 'naslov' => 'Javna']);
        $nacrt = Prilika::factory()->create(['link_izvora' => 'https://a.test/nacrt', 'rok' => '2026-11-20']);
        $arhivirana = Prilika::factory()->arhivirana()->create(['link_izvora' => 'https://a.test/arhivirana', 'rok' => '2026-11-20']);
        $prosla = Prilika::factory()->objavljena()->create(['link_izvora' => 'https://a.test/prosla', 'rok' => '2026-10-01']);
        Http::fake(['https://a.test/javna' => Http::response($this->snimak('erasmus-mentorski-program.html'))]);

        $this->proveri('Provereno 1, vraćeno u nacrt 0, nov rok 0.');

        $this->assertSame(['https://a.test/javna'], Http::recorded()->map(fn (array $par) => $par[0]->url())->values()->all());
        $this->assertSame([StatusPrilike::Nacrt, StatusPrilike::Arhivirano, StatusPrilike::Objavljeno], [$nacrt->fresh()->status, $arhivirana->fresh()->status, $prosla->fresh()->status]);
    }

    // Dva prolaza nad istim stranama daju isti ishod, a bez modela: jedini zahtevi su adrese prilika.
    #[Test]
    public function dva_prolaza_daju_isti_ishod_i_model_se_ne_zove(): void
    {
        $this->javna(['link_izvora' => 'https://a.test/zatvoren', 'naslov' => 'Zatvoren']);
        $this->javna(['link_izvora' => 'https://a.test/produzen', 'naslov' => 'Produžen', 'rok' => '2026-11-02']);
        $this->javna(['link_izvora' => 'https://a.test/u-redu', 'naslov' => 'U redu']);
        Http::fake([
            'https://a.test/zatvoren' => Http::response($this->snimak('prosveta-konkurs-istekao.html')),
            'https://a.test/produzen' => Http::response($this->snimak('prosveta-produzen-rok.html')),
            'https://a.test/u-redu' => Http::response($this->snimak('erasmus-mentorski-program.html')),
        ]);

        Artisan::call('prilike:provera');
        $prvi = Artisan::output();
        $stanje = Prilika::query()->orderBy('id')->get(['id', 'status', 'rok', 'predlog'])->toArray();
        Artisan::call('prilike:provera');

        $this->assertStringContainsString('Provereno 3, vraćeno u nacrt 1, nov rok 1.', $prvi);
        $this->assertSame(['https://a.test/zatvoren', 'https://a.test/produzen', 'https://a.test/u-redu', 'https://a.test/produzen', 'https://a.test/u-redu'], Http::recorded()->map(fn (array $par) => $par[0]->url())->values()->all());
        $this->assertStringContainsString('Provereno 2, vraćeno u nacrt 0, nov rok 0.', Artisan::output());
        $this->assertSame($stanje, Prilika::query()->orderBy('id')->get(['id', 'status', 'rok', 'predlog'])->toArray());
    }

    #[Test]
    public function dnevni_posao_proverava_javne_prilike_posle_obrade_a_pre_kalendara(): void
    {
        config()->set('uvoz.izvori', []);
        config()->set('uvoz.spiskovi', []);
        Http::fake(fn (Request $zahtev) => Http::response(''));

        $this->assertSame(0, Artisan::call('uvoz:dnevno'));
        $ispis = Artisan::output();

        $redosled = array_map(fn (string $komanda) => strpos($ispis, '→ '.$komanda), ['uvoz:obradi', 'prilike:provera', 'prilike:arhiviraj-stare', 'kalendar:iz-prilika']);
        $this->assertNotContains(false, $redosled);
        $this->assertSame($redosled, collect($redosled)->sort()->values()->all());
    }
}
