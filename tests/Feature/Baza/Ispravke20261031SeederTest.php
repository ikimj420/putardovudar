<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use Database\Seeders\Ispravke20261031Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 94 (Ivan, 30.10.2026.: „zašto ja, zašto ne AI"): jednokratne ispravke iz pregleda. Stanje „zatečeno" je preuzeto iz radne baze (samo čitanje, 31.10.2026.);
// seeder upisuje polje samo kad je u bazi vrednost koja se očekuje.
#[Group('baza')]
class Ispravke20261031SeederTest extends BazaTestCase
{
    use RefreshDatabase;

    private const NEDOSTAJUCA = Ispravke20261031Seeder::NEDOSTAJUCA_ZANIMANJA;

    private const STUDENTSKE = Ispravke20261031Seeder::STUDENTSKE_STIPENDIJE;

    private const ERAZMUS = 'podrska-organizacijama-koje-rade-sa-mladima-i-zele-da-naprave-prvi-korak-u-erazmus-programu';

    private const DOMOVI = 'konkurs-za-prijem-studenata-visokoskolskih-ustanova-u-republici-srbiji-u-ustanove-za-smestaj-i-ishranu-studenata-za-skolsku-20262027-godinu';

    private const OPREMA = 'javni-poziv-za-dodelu-opreme';

    private const HORIZON = 'horizon-europe-excellence-hubs-2027-podrska-regionalnim-inovacionim-ekosistemima';

    private const STARI_LINK_NEDOSTAJUCA = 'https://prosveta.gov.rs/vesti/raspisan-konkurs-za-stipendije-za-nedostajuca-zanimanja-u-prosveti/';

    private const STARI_LINK_STUDENTSKE = 'https://prosveta.gov.rs/vesti/raspisani-konkursi-za-dodelu-studentskih-stipendija-i-kredita-i-stipendije-za-nedostajuca-zanimanja-u-prosveti-za-skolsku-2026-2027-godinu/';

    private const VEST = 'https://prosveta.gov.rs/vesti/produzen-rok-za-da-prijavu-za-studentske-kredite-studentske-stipendije-i-stipendija-za-nedostajuca-zanimanja/';

    private const STAVKA_STUDENTSKE = 'Studentske stipendije i krediti';

    private const STAVKA_NASTAVNICI = 'Stipendije za buduće nastavnike (nedostajuća zanimanja)';

    // Vrednost koja nije ona koju seeder očekuje, za svako polje koje menja.
    private const NEOCEKIVANO = ['rok' => '2026-12-24', 'link_izvora' => 'https://a.test/drugo', 'grupe' => ['romi'], 'mesto' => 'Niš', 'status' => 'arhivirano'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(string $slug, array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => 'Prilika '.substr($slug, 0, 20), 'slug' => $slug, 'vrsta' => VrstaPrilike::Konkurs, 'grupe' => ['studenti'], 'rok' => '2026-11-20',
            'mesto' => 'Niš', 'naziv_izvora' => 'Izvor A', 'link_izvora' => 'https://a.test/'.$slug,
        ], ...$dopuna]);
    }

    /** @return array<string, Prilika> šest prilika onako kako stoje u radnoj bazi 31.10.2026. */
    private function zateceno(): array
    {
        return [
            'nedostajuca' => $this->prilika(self::NEDOSTAJUCA, ['vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-11-02', 'link_izvora' => self::STARI_LINK_NEDOSTAJUCA]),
            'studentske' => $this->prilika(self::STUDENTSKE, ['vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-10-31', 'link_izvora' => self::STARI_LINK_STUDENTSKE]),
            'erazmus' => $this->prilika(self::ERAZMUS, ['grupe' => ['mladi', 'organizacije'], 'rok' => '2026-11-10']),
            'domovi' => $this->prilika(self::DOMOVI, ['rok' => '2026-11-05']),
            'oprema' => $this->prilika(self::OPREMA, ['grupe' => ['mladi', 'romi'], 'rok' => '2026-11-10', 'mesto' => null]),
            'horizon' => $this->prilika(self::HORIZON, ['grupe' => ['organizacije'], 'rok' => '2027-03-04']),
        ];
    }

    private function stavka(string $naziv, array $dopuna = []): KalendarStavka
    {
        return KalendarStavka::factory()->objavljena()->create([...['naziv' => $naziv, 'otvoreno_do' => '2025-12-01'], ...$dopuna]);
    }

    private function stavke(): void
    {
        $this->stavka(self::STAVKA_STUDENTSKE);
        $this->stavka(self::STAVKA_NASTAVNICI);
    }

    private function pusti(): string
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => Ispravke20261031Seeder::class]));

        return Artisan::output();
    }

    private function procitaj(Prilika $prilika, string $polje): mixed
    {
        $prilika = $prilika->fresh();

        return match ($polje) {
            'rok' => $prilika->rok?->toDateString(),
            'status' => $prilika->status->value,
            'grupe' => $prilika->grupe,
            default => $prilika->getAttribute($polje),
        };
    }

    /** @return array<string, array<string, mixed>> */
    private function stanjePrilika(): array
    {
        return Prilika::query()->orderBy('id')->get()->mapWithKeys(fn (Prilika $prilika) => [$prilika->slug => $prilika->getAttributes()])->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function stanjeStavki(): array
    {
        return KalendarStavka::query()->orderBy('id')->get()->mapWithKeys(fn (KalendarStavka $stavka) => [$stavka->naziv => $stavka->getAttributes()])->all();
    }

    #[Test]
    public function zatecena_stanja_se_ispravljaju_a_slicne_prilike_i_stavke_ostaju_kako_su(): void
    {
        $p = $this->zateceno();
        $this->stavke();
        $slicna = $this->prilika('slicna-prilika', ['vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-11-02', 'link_izvora' => self::STARI_LINK_NEDOSTAJUCA]);
        $druga = $this->stavka('Neka druga stavka');
        $slicnaPre = $slicna->fresh()->getAttributes();
        $drugaPre = $druga->fresh()->getAttributes();

        $izlaz = $this->pusti();

        foreach (['nedostajuca', 'studentske'] as $kljuc) {
            $this->assertSame('2026-11-15', $this->procitaj($p[$kljuc], 'rok'), $kljuc);
            $this->assertSame(self::VEST, $this->procitaj($p[$kljuc], 'link_izvora'), $kljuc);
            $this->assertSame('objavljeno', $this->procitaj($p[$kljuc], 'status'), $kljuc);
        }

        $this->assertSame(['organizacije'], $this->procitaj($p['erazmus'], 'grupe'));
        $this->assertSame('2026-11-02', $this->procitaj($p['domovi'], 'rok'));
        $this->assertSame('Mladenovac', $this->procitaj($p['oprema'], 'mesto'));
        $this->assertSame('nacrt', $this->procitaj($p['horizon'], 'status'));

        // Ostala polja istih prilika se ne diraju: ni grupe, ni rok, ni status tamo gde nisu na spisku.
        $this->assertSame(['studenti'], $this->procitaj($p['domovi'], 'grupe'));
        $this->assertSame('2026-11-10', $this->procitaj($p['erazmus'], 'rok'));
        $this->assertSame('objavljeno', $this->procitaj($p['erazmus'], 'status'));
        $this->assertSame(['mladi', 'romi'], $this->procitaj($p['oprema'], 'grupe'));
        $this->assertSame('2027-03-04', $this->procitaj($p['horizon'], 'rok'));

        $this->assertSame((int) $p['studentske']->getKey(), KalendarStavka::query()->where('naziv', self::STAVKA_STUDENTSKE)->value('prilika_id'));
        $this->assertSame((int) $p['nedostajuca']->getKey(), KalendarStavka::query()->where('naziv', self::STAVKA_NASTAVNICI)->value('prilika_id'));
        $this->assertSame(10, substr_count($izlaz, 'Promenjeno: '));
        $this->assertStringContainsString('Promenjeno 10, već ispravljeno 0, preskočeno 0.', $izlaz);

        // Ispis kaže šta je bilo i šta je sada, na srpskom i bez dvostruke tačke posle datuma.
        foreach (['rok: bilo 02.11.2026., sada 15.11.2026.', 'rok: bilo 05.11.2026., sada 02.11.2026.', 'mesto: bilo prazno, sada „Mladenovac“.', 'grupe: bilo „Mladi“, „Organizacije“, sada „Organizacije“.', 'status: bilo „objavljeno“, sada „nacrt“.'] as $red) {
            $this->assertStringContainsString($red, $izlaz);
        }
        $this->assertDoesNotMatchRegularExpression('/\d\.\./', $izlaz);

        // Ogledalo: prilika sa istim rokom i linkom, ali drugim slugom, i stavka drugog naziva ostaju netaknute.
        $this->assertSame($slicnaPre, $slicna->fresh()->getAttributes());
        $this->assertSame($drugaPre, $druga->fresh()->getAttributes());
    }

    // Merilo je obišlo neprazan skup: na spisku je osam ispravki prilika i dve veze, a prvi test gore i drugo puštanje dole broje svih deset.
    #[Test]
    public function spisak_ima_osam_ispravki_prilika_i_dve_veze_kalendara(): void
    {
        $this->assertCount(8, Ispravke20261031Seeder::ispravkePrilika());
        $this->assertCount(2, Ispravke20261031Seeder::vezeKalendara());
        $this->assertCount(8, array_unique(array_map(fn (array $i) => $i['slug'].'|'.$i['polje'], Ispravke20261031Seeder::ispravkePrilika())));
    }

    /** @return array<string, array{int}> */
    public static function ispravke(): array
    {
        $sve = [];

        foreach (Ispravke20261031Seeder::ispravkePrilika() as $broj => $ispravka) {
            $sve[$ispravka['polje'].' · '.substr($ispravka['slug'], 0, 30)] = [$broj];
        }

        return $sve;
    }

    #[Test]
    #[DataProvider('ispravke')]
    public function ispravka_se_upisuje_kad_je_u_bazi_ocekivana_vrednost(int $broj): void
    {
        $ispravka = Ispravke20261031Seeder::ispravkePrilika()[$broj];
        $prilika = $this->prilika($ispravka['slug'], [$ispravka['polje'] => $ispravka['ocekivano']]);

        $izlaz = $this->pusti();

        $this->assertSame($ispravka['novo'], $this->procitaj($prilika, $ispravka['polje']));
        $this->assertStringContainsString('Promenjeno: prilika '.$prilika->getKey().' ', $izlaz);
        $this->assertStringContainsString('Promenjeno 1,', $izlaz);
        $this->assertDoesNotMatchRegularExpression('/\d\.\./', $izlaz);
    }

    // Ogledalo: druga vrednost od očekivane ostaje kakva jeste, a ispis kaže zašto.
    #[Test]
    #[DataProvider('ispravke')]
    public function ispravka_se_preskace_kad_u_bazi_nije_ocekivana_vrednost(int $broj): void
    {
        $ispravka = Ispravke20261031Seeder::ispravkePrilika()[$broj];
        $this->assertNotSame($ispravka['ocekivano'], self::NEOCEKIVANO[$ispravka['polje']]);
        $this->assertNotSame($ispravka['novo'], self::NEOCEKIVANO[$ispravka['polje']]);
        $prilika = $this->prilika($ispravka['slug'], [$ispravka['polje'] => self::NEOCEKIVANO[$ispravka['polje']]]);
        $pre = $prilika->fresh()->getAttributes();

        $izlaz = $this->pusti();

        $this->assertSame($pre, $prilika->fresh()->getAttributes());
        $this->assertStringContainsString('Preskočeno: prilika '.$prilika->getKey().' ', $izlaz);
        $this->assertStringContainsString(', a očekivano je ', $izlaz);
        $this->assertStringContainsString('Promenjeno 0,', $izlaz);
        $this->assertDoesNotMatchRegularExpression('/\d\.\./', $izlaz);
    }

    #[Test]
    #[DataProvider('ispravke')]
    public function vec_ispravljena_vrednost_se_ne_upisuje_ponovo(int $broj): void
    {
        $ispravka = Ispravke20261031Seeder::ispravkePrilika()[$broj];
        $prilika = $this->prilika($ispravka['slug'], [$ispravka['polje'] => $ispravka['novo']]);
        $pre = $prilika->fresh()->getAttributes();

        $this->travelTo(now()->addHour());
        $izlaz = $this->pusti();

        $this->assertSame($pre, $prilika->fresh()->getAttributes());
        $this->assertStringContainsString('Već ispravljeno: prilika '.$prilika->getKey().' ', $izlaz);
    }

    #[Test]
    public function drugo_pustanje_ne_menja_nista(): void
    {
        $this->zateceno();
        $this->stavke();
        $this->pusti();
        $prilike = $this->stanjePrilika();
        $stavke = $this->stanjeStavki();

        $this->travelTo(now()->addHour());
        $izlaz = $this->pusti();

        $this->assertCount(6, $prilike);
        $this->assertSame($prilike, $this->stanjePrilika());
        $this->assertSame($stavke, $this->stanjeStavki());
        $this->assertStringContainsString('Promenjeno 0, već ispravljeno 10, preskočeno 0.', $izlaz);
    }

    // Rok koji je prošao posle 31.10. ne sme da zaustavi ispravku: status ostaje objavljeno, a prilika opet postaje javna i može da se veže za kalendar.
    #[Test]
    public function prilika_kojoj_je_rok_vec_prosao_se_ispravlja_a_status_ostaje_objavljeno(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 3, 12, 0, 0, 'Europe/Belgrade'));
        $p = $this->zateceno();
        $this->stavke();

        foreach (['nedostajuca', 'studentske'] as $kljuc) {
            $this->assertFalse(KalendarStavka::prilikaJeJavna($p[$kljuc]->getKey()), $kljuc);
        }

        $this->pusti();

        foreach (['nedostajuca', 'studentske'] as $kljuc) {
            $this->assertSame('2026-11-15', $this->procitaj($p[$kljuc], 'rok'), $kljuc);
            $this->assertSame('objavljeno', $this->procitaj($p[$kljuc], 'status'), $kljuc);
            $this->assertTrue(KalendarStavka::prilikaJeJavna($p[$kljuc]->getKey()), $kljuc);
        }

        $this->assertSame('2026-11-02', $this->procitaj($p['domovi'], 'rok'));
        $this->assertSame('objavljeno', $this->procitaj($p['domovi'], 'status'));
        $this->assertSame(2, KalendarStavka::query()->whereNotNull('prilika_id')->count());
    }

    #[Test]
    public function stavka_se_vezuje_samo_za_javnu_priliku(): void
    {
        $p = $this->zateceno();
        $this->stavke();
        $p['studentske']->update(['status' => StatusPrilike::Nacrt]);

        $izlaz = $this->pusti();

        $this->assertNull(KalendarStavka::query()->where('naziv', self::STAVKA_STUDENTSKE)->value('prilika_id'));
        $this->assertStringContainsString('Preskočeno: stavka kalendara „'.self::STAVKA_STUDENTSKE.'“: prilika '.$p['studentske']->getKey().' ', $izlaz);
        // Ogledalo: druga stavka je u istom prolazu povezana, jer je njena prilika javna.
        $this->assertSame((int) $p['nedostajuca']->getKey(), KalendarStavka::query()->where('naziv', self::STAVKA_NASTAVNICI)->value('prilika_id'));
        // Rok iz ispravke je upisan i na prilici koja nije javna: status se ne dira.
        $this->assertSame('2026-11-15', $this->procitaj($p['studentske'], 'rok'));
        $this->assertSame('nacrt', $this->procitaj($p['studentske'], 'status'));
    }

    #[Test]
    public function stavka_vezana_za_drugu_priliku_ostaje_kako_je(): void
    {
        $p = $this->zateceno();
        $druga = $this->prilika('druga-prilika');
        $this->stavka(self::STAVKA_STUDENTSKE, ['prilika_id' => $druga->getKey()]);
        $this->stavka(self::STAVKA_NASTAVNICI);

        $izlaz = $this->pusti();

        $this->assertSame((int) $druga->getKey(), KalendarStavka::query()->where('naziv', self::STAVKA_STUDENTSKE)->value('prilika_id'));
        $this->assertStringContainsString('je već povezana sa prilikom '.$druga->getKey().', a očekivano je da nije povezana.', $izlaz);
        $this->assertSame((int) $p['nedostajuca']->getKey(), KalendarStavka::query()->where('naziv', self::STAVKA_NASTAVNICI)->value('prilika_id'));
    }

    #[Test]
    public function stavka_koje_nema_ili_ih_je_vise_istog_naziva_se_preskace(): void
    {
        $this->zateceno();
        $this->stavka(self::STAVKA_NASTAVNICI);
        $this->stavka(self::STAVKA_NASTAVNICI);

        $izlaz = $this->pusti();

        $this->assertSame(0, KalendarStavka::query()->whereNotNull('prilika_id')->count());
        $this->assertStringContainsString('„'.self::STAVKA_STUDENTSKE.'“: nema je u bazi.', $izlaz);
        $this->assertStringContainsString('„'.self::STAVKA_NASTAVNICI.'“: u bazi ih je više sa istim nazivom.', $izlaz);
    }

    #[Test]
    public function u_praznoj_bazi_se_sve_preskace_uz_razlog_i_nista_se_ne_pravi(): void
    {
        $izlaz = $this->pusti();

        $this->assertSame(0, Prilika::query()->count());
        $this->assertSame(0, KalendarStavka::query()->count());
        $this->assertSame(8, substr_count($izlaz, 'nema u bazi.'));
        $this->assertSame(2, substr_count($izlaz, 'nema je u bazi.'));
        $this->assertStringContainsString('Promenjeno 0, već ispravljeno 0, preskočeno 10.', $izlaz);
    }

    /** @return array{Prilika, Prilika} dve prilike Ministarstva prosvete sa starim linkovima iz zatečenog stanja */
    private function dveProsvetine(string $rok): array
    {
        return [
            $this->prilika(self::NEDOSTAJUCA, ['vrsta' => VrstaPrilike::Stipendija, 'rok' => $rok, 'link_izvora' => self::STARI_LINK_NEDOSTAJUCA]),
            $this->prilika(self::STUDENTSKE, ['vrsta' => VrstaPrilike::Stipendija, 'rok' => $rok, 'link_izvora' => self::STARI_LINK_STUDENTSKE]),
        ];
    }

    #[Test]
    public function posle_seedera_provera_ne_vraca_nijednu_od_dve_u_nacrt(): void
    {
        [$nedostajuca, $studentske] = $this->dveProsvetine('2026-11-02');
        $studentske->update(['rok' => '2026-10-31']);
        $this->pusti();
        $this->stranaJe([self::VEST => (string) file_get_contents(base_path('tests/Fixtures/stranice/prosveta-produzen-rok.html'))]);

        $this->assertSame(0, Artisan::call('prilike:provera'));

        $this->assertStringContainsString('Provereno 2, vraćeno u nacrt 0, nov rok 0.', Artisan::output());
        foreach ([$nedostajuca, $studentske] as $prilika) {
            $this->assertSame('objavljeno', $this->procitaj($prilika, 'status'));
            $this->assertSame('2026-11-15', $this->procitaj($prilika, 'rok'));
        }
    }

    // Ogledalo, i razlog zašto ide i link: rok 15.11. na staroj strani ne piše, pa bi ih dnevna provera vratila u nacrt.
    #[Test]
    public function sa_starim_linkom_bi_provera_vratila_obe_u_nacrt(): void
    {
        [$nedostajuca, $studentske] = $this->dveProsvetine('2026-11-15');
        $this->stranaJe([
            self::STARI_LINK_NEDOSTAJUCA => '<html><body><article><h1>Konkurs</h1><p>Rok za prijavu je 2. novembra 2026. godine.</p></article></body></html>',
            self::STARI_LINK_STUDENTSKE => '<html><body><article><h1>Konkurs</h1><p>Rok za prijavu je 31. oktobra 2026. godine.</p></article></body></html>',
        ]);

        $this->assertSame(0, Artisan::call('prilike:provera'));

        $this->assertStringContainsString('Provereno 2, vraćeno u nacrt 2, nov rok 0.', Artisan::output());
        foreach ([$nedostajuca, $studentske] as $prilika) {
            $this->assertSame('nacrt', $this->procitaj($prilika, 'status'));
        }
    }

    /** @param  array<string, string>  $strane */
    private function stranaJe(array $strane): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(array_map(fn (string $telo) => Http::response($telo, 200, ['Content-Type' => 'text/html; charset=UTF-8']), $strane));
    }
}
