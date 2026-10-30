<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusObjave;
use App\Models\KalendarStavka;
use App\Support\LinkSajta;
use App\Support\Prikaz;
use App\Support\PrikazKalendara;
use Database\Seeders\KalendarProslaGodinaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 83 (Ivan, 29.10.2026.): 36 stavki „šta je bilo otvoreno prošli put" iz Dokumentacija/KALENDAR-PROSLA-GODINA.md, doslovno; seeder dodaje samo stavku koje još nema.
#[Group('baza')]
class KalendarProslaGodinaSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    private const BROJ = 36;

    protected function setUp(): void
    {
        parent::setUp();

        // Svi rokovi su pre 29.10.2026., pa su sve stavke prošle (javne) od ovog dana.
        $this->travelTo(Carbon::create(2026, 10, 30, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<int, array<string, mixed>> */
    private function stanje(): array
    {
        return KalendarStavka::query()->orderBy('id')->get()->map(fn (KalendarStavka $stavka) => $stavka->getAttributes())->all();
    }

    #[Test]
    public function seeder_pravi_trideset_sest_objavljenih_stavki_i_sve_su_javne(): void
    {
        $this->seed(KalendarProslaGodinaSeeder::class);

        $this->assertCount(self::BROJ, KalendarProslaGodinaSeeder::stavke());
        $this->assertSame(self::BROJ, KalendarStavka::query()->count());
        $this->assertSame(self::BROJ, KalendarStavka::query()->where('status', StatusObjave::Objavljeno)->count());
        $this->assertSame(self::BROJ, KalendarStavka::javne()->count());
        $this->assertSame(self::BROJ, KalendarStavka::query()->distinct()->count('naziv'));

        foreach (KalendarStavka::query()->get() as $stavka) {
            $this->assertTrue(LinkSajta::jeIspravan($stavka->link_izvora), $stavka->naziv);
            $this->assertNotSame('', trim((string) $stavka->ko_daje), $stavka->naziv);
            $this->assertNotSame('', trim((string) $stavka->napomena), $stavka->naziv);
            $this->assertNotEmpty($stavka->grupe, $stavka->naziv);
            $this->assertLessThan('2026-10-29', $stavka->otvoreno_do->toDateString(), $stavka->naziv);
            $this->assertTrue($stavka->otvoreno_od === null || $stavka->otvoreno_od->lessThanOrEqualTo($stavka->otvoreno_do), $stavka->naziv);
            $this->assertSame([], array_diff($stavka->grupe, array_map(fn (Grupa $grupa) => $grupa->value, Grupa::cases())), $stavka->naziv);
        }
    }

    // Ogledalo: i kad se pusti ponovo (sat se pomeri), ništa se ne dodaje i nijedan red se ne dira.
    #[Test]
    public function drugo_pokretanje_ne_menja_nista(): void
    {
        $this->seed(KalendarProslaGodinaSeeder::class);
        $pre = $this->stanje();

        $this->travelTo(now()->addHour());
        $this->seed(KalendarProslaGodinaSeeder::class);

        $this->assertCount(self::BROJ, $pre);
        $this->assertSame($pre, $this->stanje());
    }

    // Pet grupa stavki deli rok i link (npr. dve Erasmus+ stavke od 12.2.2026.): u praznu bazu ulaze sve, a svaka je posebna stavka.
    #[Test]
    public function stavke_koje_dele_rok_i_link_ulaze_sve_u_praznu_bazu(): void
    {
        $grupe = collect(KalendarProslaGodinaSeeder::stavke())->groupBy(fn (array $stavka) => $stavka['otvoreno_do'].' '.rtrim($stavka['link_izvora'], '/'))->filter(fn ($deo) => $deo->count() > 1);

        $this->assertSame(5, $grupe->count(), 'merilo mora da nađe pet grupa sa istim rokom i linkom');

        $this->seed(KalendarProslaGodinaSeeder::class);

        foreach ($grupe as $deo) {
            $naziviIzBaze = KalendarStavka::query()->whereDate('otvoreno_do', $deo->first()['otvoreno_do'])->pluck('naziv')->all();
            $this->assertEqualsCanonicalizing($deo->pluck('naziv')->all(), array_values(array_intersect($naziviIzBaze, $deo->pluck('naziv')->all())));
        }
    }

    // Stavka koja već postoji (isti rok i isti link, kao KalendarIzPrilike; kosa crta na kraju ne smeta) se ne dira; ostale se dodaju. Ogledala: isti link sa drugim rokom i isti rok sa drugim linkom su druge stavke.
    #[Test]
    public function postojeca_stavka_se_ne_dira_a_ostale_se_dodaju(): void
    {
        $prva = KalendarProslaGodinaSeeder::stavke()[0];
        $ivanova = KalendarStavka::factory()->create(['naziv' => 'Ivanov naziv', 'ko_daje' => 'Ivan', 'otvoreno_do' => $prva['otvoreno_do'], 'link_izvora' => $prva['link_izvora'].'/', 'napomena' => 'Moja napomena', 'status' => StatusObjave::Nacrt]);
        $drugiRok = KalendarStavka::factory()->create(['naziv' => 'Isti link, drugi rok', 'otvoreno_do' => '2025-01-01', 'link_izvora' => $prva['link_izvora']]);
        $druga = KalendarProslaGodinaSeeder::stavke()[1];
        $drugiLink = KalendarStavka::factory()->create(['naziv' => 'Isti rok, drugi link', 'otvoreno_do' => $druga['otvoreno_do'], 'link_izvora' => 'https://drugi.example.org/']);

        $this->seed(KalendarProslaGodinaSeeder::class);

        $this->assertSame(self::BROJ + 2, KalendarStavka::query()->count(), 'prva stavka je preskočena, a stavke sa drugim rokom ili drugim linkom ne smetaju nijednoj');
        $this->assertSame(['Ivanov naziv', 'Ivan', 'Moja napomena', StatusObjave::Nacrt], [$ivanova->fresh()->naziv, $ivanova->fresh()->ko_daje, $ivanova->fresh()->napomena, $ivanova->fresh()->status]);
        $this->assertSame(0, KalendarStavka::query()->where('naziv', $prva['naziv'])->count());
        $this->assertSame(1, KalendarStavka::query()->where('naziv', $druga['naziv'])->count(), 'isti rok sa drugim linkom ne pokriva drugu stavku');
        $this->assertModelExists($drugiRok);
        $this->assertModelExists($drugiLink);
    }

    // Pravilo iz paketa „isti rok i isti link" znači i ovo: stavka u bazi pokriva sve stavke liste sa istim rokom i linkom (merenje, PITANJA-83.md).
    #[Test]
    public function jedna_postojeca_stavka_pokriva_sve_iz_liste_sa_istim_rokom_i_linkom(): void
    {
        $erasmus = collect(KalendarProslaGodinaSeeder::stavke())->where('otvoreno_do', '2026-02-12')->values();
        $this->assertCount(2, $erasmus);
        KalendarStavka::factory()->create(['otvoreno_do' => '2026-02-12', 'link_izvora' => $erasmus[0]['link_izvora']]);

        $this->seed(KalendarProslaGodinaSeeder::class);

        $this->assertSame(self::BROJ - 2 + 1, KalendarStavka::query()->count());
        $this->assertSame(0, KalendarStavka::query()->whereIn('naziv', $erasmus->pluck('naziv')->all())->count());
    }

    #[Test]
    public function tekstovi_su_doslovno_isti_kao_u_dokumentu_i_samo_odobreni(): void
    {
        $putanja = base_path('Dokumentacija/KALENDAR-PROSLA-GODINA.md');

        if (! is_file($putanja)) {
            $this->markTestSkipped('Dokumentacija/KALENDAR-PROSLA-GODINA.md nema na ovom računaru (folder Dokumentacija je van gita), pa se tekstovi ne mogu uporediti.');
        }

        $dokument = (string) file_get_contents($putanja);
        [$telo, $neUnosi] = explode('## Nije uneto', $dokument, 2);
        $odeljci = preg_split('/^### /m', $telo) ?: [];
        array_shift($odeljci);
        $poredjeno = 0;
        $kljucevi = [];

        foreach ($odeljci as $odeljak) {
            preg_match('/^`([a-z0-9-]+)` — (.+)$/m', (string) strtok($odeljak, "\n"), $glava);
            preg_match_all('/^- ([^:]+): (.*)$/m', $odeljak, $nalazi, PREG_SET_ORDER);
            $polja = array_column($nalazi, 2, 1);
            $stavka = collect(KalendarProslaGodinaSeeder::stavke())->firstWhere('kljuc', $glava[1]);

            $this->assertNotNull($stavka, $glava[1]);
            $this->assertSame($glava[2], $stavka['naziv'], $glava[1]);
            $this->assertSame($polja['Ko daje'], $stavka['ko_daje'], $glava[1]);
            $this->assertEqualsCanonicalizing(array_map('trim', explode(',', $polja['Kome'])), $stavka['grupe'], $glava[1]);
            // „(nema)" je prazno polje.
            $this->assertSame($polja['Otvoreno od'] === '(nema)' ? null : $this->dan($polja['Otvoreno od']), $stavka['otvoreno_od'], $glava[1]);
            $this->assertSame($this->dan($polja['Otvoreno do']), $stavka['otvoreno_do'], $glava[1]);
            $this->assertSame($polja['Link izvora'], $stavka['link_izvora'], $glava[1]);
            $this->assertSame($polja['Napomena'], $stavka['napomena'], $glava[1]);
            $kljucevi[] = $glava[1];
            $poredjeno++;
        }

        $this->assertSame(self::BROJ, $poredjeno);
        // Obrnuto: svaka stavka seedera je u dokumentu, i ništa više.
        $this->assertEqualsCanonicalizing(array_column(KalendarProslaGodinaSeeder::stavke(), 'kljuc'), $kljucevi);
        // Odeljak „Nije uneto" postoji (neprazan skup za merilo), a ništa iz njega nije u seederu.
        foreach (['Moja prva plata', 'Roma Youth Together', 'Roma Holocaust Voices Fellowship'] as $nijeUneto) {
            $this->assertStringContainsString($nijeUneto, $neUnosi);
            $this->assertSame([], array_values(array_filter(array_column(KalendarProslaGodinaSeeder::stavke(), 'naziv'), fn (string $naziv) => str_contains($naziv, $nijeUneto))), $nijeUneto);
        }
    }

    private function dan(string $tekst): string
    {
        return Carbon::createFromFormat('j.n.Y.', trim($tekst))->toDateString();
    }

    // Strana „Kalendar" posle seedera: dvanaest meseci od tekućeg (oktobar 2026. do septembra 2027.), svaka stavka u mesecu svog roka, ukupno sve.
    #[Test]
    public function strana_kalendara_ima_dvanaest_meseci_od_tekuceg_i_svaka_stavka_je_u_mesecu_svog_roka(): void
    {
        $this->seed(KalendarProslaGodinaSeeder::class);

        $html = $this->get(route('kalendar.index'))->assertOk()->getContent();
        preg_match_all('#<h2 class="naslov-izvora">([^<]+)</h2>\s*<div class="spisak">(.*?)</div>\s*(?=<h2 class="naslov-izvora">|</div>\s*</main>)#s', $html, $meseci, PREG_SET_ORDER);

        $this->assertSame(['Oktobar', 'Novembar', 'Decembar', 'Januar', 'Februar', 'Mart', 'April', 'Maj', 'Jun', 'Jul', 'Avgust', 'Septembar'], array_map(fn (array $mesec) => trim($mesec[1]), $meseci));

        $ukupno = 0;
        foreach ($meseci as $mesec) {
            $mesecBroj = array_search(trim($mesec[1]), array_map(fn (int $broj) => Prikaz::mesec($broj), range(1, 12)), true) + 1;
            $ocekivano = KalendarStavka::query()->whereMonth('otvoreno_do', $mesecBroj)->pluck('naziv')->all();
            preg_match_all('#<article class="kartica">.*?<h3>(.*?)</h3>#s', $mesec[2], $nazivi);

            $this->assertEqualsCanonicalizing($ocekivano, array_map(fn (string $naziv) => html_entity_decode(trim(strip_tags($naziv))), $nazivi[1]), $mesec[1]);
            $ukupno += count($nazivi[1]);
        }

        $this->assertSame(self::BROJ, $ukupno);
        $dositeja = KalendarStavka::query()->where('naziv', 'like', 'Stipendije „Dositeja%')->sole();
        $this->assertSame('Prošli put: od 22.12.2025. do 26.01.2026.', PrikazKalendara::prosliPut($dositeja));
        $this->assertStringContainsString('Prošli put: od 22.12.2025. do 26.01.2026.', $html);
    }

    // Nijedna spoljna adresa osim linkova izvora: svaka veza van sajta je link izvora neke stavke, i svi linkovi izvora su na strani (neprazan skup).
    #[Test]
    public function strana_kalendara_nema_spoljnih_adresa_osim_linkova_izvora(): void
    {
        $this->seed(KalendarProslaGodinaSeeder::class);

        $html = $this->get(route('kalendar.index'))->assertOk()->getContent();
        $moj = parse_url(url('/'), PHP_URL_HOST);
        preg_match_all('#href="(https?://[^"]+)"#', $html, $veze);
        $spoljne = array_values(array_unique(array_filter(array_map('html_entity_decode', $veze[1]), fn (string $veza) => parse_url($veza, PHP_URL_HOST) !== $moj)));
        $izvori = array_values(array_unique(array_column(KalendarProslaGodinaSeeder::stavke(), 'link_izvora')));

        $this->assertNotEmpty($spoljne);
        $this->assertEqualsCanonicalizing($izvori, $spoljne);
    }

    // Početna: pločica kalendara broji mesece sa stavkama i vodi na kalendar. Ogledalo: bez stavki je nula.
    #[Test]
    public function plocica_kalendara_na_pocetnoj_broji_mesece_i_vodi_na_kalendar(): void
    {
        $this->get(route('pocetna'))->assertOk()->assertSee('<b>0</b> <span>meseci u kalendaru</span>', false);

        $this->seed(KalendarProslaGodinaSeeder::class);

        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a class="plocica" data-strana="kalendar" href="'.preg_quote(route('kalendar.index'), '#').'"><b>12</b> <span>meseci u kalendaru</span></a>#', $html);
        $this->assertSame(12, KalendarStavka::brojMesecaSaStavkama());
    }
}
