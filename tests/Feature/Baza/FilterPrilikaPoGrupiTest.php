<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 42: red dugmadi „Ja sam…" na spisku prilika; obične veze (?grupa=), bez skripta, samo kroz Prilika::javne(), uz vrstu ili bez nje.
#[Group('baza')]
class FilterPrilikaPoGrupiTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** Po jedna javna prilika za svaku grupu (vrsta posao), naslovom „Za <grupa>". */
    private function sveGrupe(): void
    {
        foreach (Grupa::cases() as $grupa) {
            Prilika::factory()->objavljena()->zaGrupe($grupa)->create(['naslov' => 'Za '.$grupa->value, 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        }
    }

    /** @return list<string> */
    private function naslovi(string $html): array
    {
        preg_match_all('#<article class="kartica redosled">\s*<h2><a [^>]*>([^<]+)</a></h2>#', $html, $nalazi);

        return $nalazi[1];
    }

    /** @return list<array{0: string, 1: string, 2: bool, 3: bool}> naziv, adresa, klasa „aktivno", aria-current */
    private function dugmad(string $html, string $red = 'filteri grupe'): array
    {
        preg_match('#<nav class="'.$red.'"[^>]*>(.*?)</nav>#s', $html, $filteri);
        preg_match_all('#<a class="filter-pilula( aktivno)?" href="([^"]+)"( aria-current="true")?>([^<]+)</a>#', $filteri[1] ?? '', $nalazi, PREG_SET_ORDER);

        return array_map(fn (array $dugme) => [$dugme[4], html_entity_decode($dugme[2]), $dugme[1] !== '', $dugme[3] !== ''], $nalazi);
    }

    /** @return array<string, array{0: Grupa}> */
    public static function grupe(): array
    {
        return array_combine(array_map(fn (Grupa $g) => $g->value, Grupa::cases()), array_map(fn (Grupa $g) => [$g], Grupa::cases()));
    }

    #[Test]
    #[DataProvider('grupe')]
    public function adresa_sa_grupom_pokazuje_samo_prilike_te_grupe(Grupa $grupa): void
    {
        $this->sveGrupe();

        $html = $this->get(route('prilike.index', ['grupa' => $grupa->value]))->assertOk()->getContent();

        $this->assertSame(['Za '.$grupa->value], $this->naslovi($html));
        // Ogledalo: bez grupe su sve, a sa drugom grupom ne ostaje ova.
        $this->assertCount(count(Grupa::cases()), $this->naslovi($this->get(route('prilike.index'))->getContent()));
        $druga = $grupa === Grupa::Studenti ? 'romi' : 'studenti';
        $this->assertNotContains('Za '.$grupa->value, $this->naslovi($this->get(route('prilike.index', ['grupa' => $druga]))->getContent()));
    }

    // Prilika za više grupa ulazi u spisak svake od njih.
    #[Test]
    public function prilika_za_vise_grupa_je_u_spisku_svake_od_njih(): void
    {
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti, Grupa::Romi)->create(['naslov' => 'Za oboje', 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Osnovci)->create(['naslov' => 'Za osnovce', 'rok' => '2026-12-15']);

        $this->assertSame(['Za oboje'], $this->naslovi($this->get(route('prilike.index', ['grupa' => 'studenti']))->getContent()));
        $this->assertSame(['Za oboje'], $this->naslovi($this->get(route('prilike.index', ['grupa' => 'romi']))->getContent()));
        $this->assertSame(['Za osnovce'], $this->naslovi($this->get(route('prilike.index', ['grupa' => 'osnovci']))->getContent()));
    }

    // Zajedno sa vrstom: ostaje samo ono što je i te vrste i za tu grupu.
    #[Test]
    public function grupa_i_vrsta_zajedno_daju_presek(): void
    {
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Posao za studente', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Praksa za studente', 'vrsta' => VrstaPrilike::Praksa, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Romi)->create(['naslov' => 'Posao za Rome', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Posao bez grupe', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);

        $presek = $this->get(route('prilike.index', ['vrsta' => 'posao', 'grupa' => 'studenti']))->assertOk()->getContent();

        $this->assertSame(['Posao za studente'], $this->naslovi($presek));
        // Ogledala: samo vrsta daje oba posla i posao bez grupe, a samo grupa daje posao i praksu za studente.
        $this->assertSame(['Posao za studente', 'Posao za Rome', 'Posao bez grupe'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao']))->getContent()));
        $this->assertSame(['Posao za studente', 'Praksa za studente'], $this->naslovi($this->get(route('prilike.index', ['grupa' => 'studenti']))->getContent()));
    }

    #[Test]
    public function dugmad_su_za_grupe_sa_javnom_prilikom_obicnim_vezama_u_redosledu_enuma(): void
    {
        $this->sveGrupe();

        $html = $this->get(route('prilike.index'))->assertOk()->getContent();
        $dugmad = $this->dugmad($html);

        $this->assertSame(array_map(fn (Grupa $g) => $g->getLabel(), Grupa::cases()), array_map(fn (array $d) => $d[0], $dugmad));
        $this->assertSame(array_map(fn (Grupa $g) => PrikazPrilike::adresaSpiska(null, $g), Grupa::cases()), array_map(fn (array $d) => $d[1], $dugmad));
        // Red ima natpis „Ja sam…" i ime za čitač ekrana; ispod reda vrsta, a ne umesto njega.
        $this->assertStringContainsString('<nav class="filteri grupe" aria-labelledby="ja-sam">', $html);
        $this->assertStringContainsString('<span class="filteri-naslov" id="ja-sam">Ja sam…</span>', $html);
        $this->assertLessThan(strpos($html, 'filteri grupe'), strpos($html, '<nav class="filteri"'));
        // Bez skripta: nema dugmeta, obrasca ni rukovaoca događaja.
        preg_match('#<nav class="filteri grupe"[^>]*>(.*?)</nav>#s', $html, $red);
        $this->assertNotSame('', $red[1] ?? '');
        $this->assertStringNotContainsString('<button', $red[1]);
        $this->assertStringNotContainsString('onclick', $red[1]);
        $this->assertStringNotContainsString('<script', $red[1]);
    }

    // Dugme postoji samo za grupu koja ima bar jednu javnu priliku; nacrt, arhivirano i isteklo ne računaju.
    #[Test]
    public function grupa_bez_javne_prilike_nema_dugme_a_njena_adresa_daje_sve_prilike(): void
    {
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Javna za studente', 'rok' => '2026-12-15']);
        Prilika::factory()->zaGrupe(Grupa::Osnovci)->create(['naslov' => 'Nacrt za osnovce', 'status' => StatusPrilike::Nacrt]);
        Prilika::factory()->arhivirana()->zaGrupe(Grupa::Romi)->create(['naslov' => 'Arhivirana za Rome']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Povratnici)->create(['naslov' => 'Istekla za povratnike', 'rok' => '2026-10-01']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Bez grupe', 'rok' => '2026-12-20']);

        $this->assertSame(['Studenti'], array_map(fn (array $d) => $d[0], $this->dugmad($this->get(route('prilike.index'))->getContent())));

        // Nejavna prilika te grupe ne stiže ni preko adrese: „osnovci" je nepoznata grupa, pa su sve javne prilike.
        foreach (['osnovci', 'romi', 'povratnici'] as $grupa) {
            $this->assertSame(['Javna za studente', 'Bez grupe'], $this->naslovi($this->get(route('prilike.index', ['grupa' => $grupa]))->assertOk()->getContent()), $grupa);
        }
    }

    // Nejavna prilika iste grupe kao javna ne ulazi u filtrirani spisak (kroz Prilika::javne(), ne kroz sve prilike).
    #[Test]
    public function filtrirani_spisak_ne_pokazuje_nacrt_arhivirano_i_isteklo_iste_grupe(): void
    {
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Javna', 'rok' => '2026-12-15']);
        Prilika::factory()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Nacrt', 'status' => StatusPrilike::Nacrt, 'rok' => '2026-12-15']);
        Prilika::factory()->arhivirana()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Arhivirana', 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Istekla', 'rok' => '2026-10-01']);

        $this->assertSame(['Javna'], $this->naslovi($this->get(route('prilike.index', ['grupa' => 'studenti']))->assertOk()->getContent()));
        // Ogledalo: u bazi su četiri prilike te grupe, a javna je samo jedna.
        $this->assertSame(4, Prilika::query()->zaGrupu(Grupa::Studenti)->count());
    }

    /** @return array<string, array{0: string}> */
    public static function nepoznate(): array
    {
        return [
            'izmišljena grupa' => ['firme'],
            'prazna' => [''],
            'velika slova' => ['STUDENTI'],
            'broj' => ['1'],
            'napad' => ['studenti\' OR 1=1 --'],
            'vrsta umesto grupe' => ['posao'],
        ];
    }

    #[Test]
    #[DataProvider('nepoznate')]
    public function nepoznata_grupa_u_adresi_daje_sve_prilike_a_ne_gresku(string $vrednost): void
    {
        $this->sveGrupe();

        $html = $this->get(route('prilike.index', ['grupa' => $vrednost]))->assertOk()->getContent();

        $this->assertCount(count(Grupa::cases()), $this->naslovi($html));
        $this->assertSame([], array_filter($this->dugmad($html), fn (array $d) => $d[2]));
        $this->assertSame([PrikazPrilike::SVE_PRILIKE], array_column(array_filter($this->dugmad($html, 'filteri'), fn (array $d) => $d[2]), 0));
    }

    #[Test]
    public function grupa_koja_nije_tekst_daje_sve_prilike(): void
    {
        $this->sveGrupe();

        $html = $this->get(route('prilike.index').'?grupa[]=studenti&grupa[]=romi')->assertOk()->getContent();

        $this->assertCount(count(Grupa::cases()), $this->naslovi($html));
    }

    // Aktivna je tačno jedna grupa, i klasom i oznakom za čitač ekrana; „Sve prilike" više nije aktivno.
    #[Test]
    public function aktivno_je_samo_dugme_izabrane_grupe(): void
    {
        $this->sveGrupe();

        $html = $this->get(route('prilike.index', ['grupa' => 'romi']))->getContent();
        $dugmad = $this->dugmad($html);

        $this->assertSame(['Romi i Romkinje'], array_column(array_filter($dugmad, fn (array $d) => $d[2]), 0));
        $this->assertSame(['Romi i Romkinje'], array_column(array_filter($dugmad, fn (array $d) => $d[3]), 0));
        $this->assertSame([], array_filter($this->dugmad($html, 'filteri'), fn (array $d) => $d[2]));
    }

    // Veze čuvaju drugi izbor: vrsta čuva grupu, grupa čuva vrstu, „Sve prilike" skida oba, a izabrana grupa se dodirom skida.
    #[Test]
    public function veze_cuvaju_drugi_izbor_a_sve_prilike_skida_oba(): void
    {
        $this->sveGrupe();
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Praksa za studente', 'vrsta' => VrstaPrilike::Praksa, 'rok' => '2026-12-15']);

        $html = $this->get(route('prilike.index', ['vrsta' => 'posao', 'grupa' => 'studenti']))->getContent();
        $vrste = $this->dugmad($html, 'filteri');
        $grupe = $this->dugmad($html);

        // Sve prilike: obična adresa; vrste: uz izabranu grupu; grupe: uz izabranu vrstu, a izabrana grupa vodi na isto bez nje.
        $this->assertSame(route('prilike.index'), $vrste[0][1]);
        $this->assertFalse($vrste[0][2]);
        $this->assertSame(PrikazPrilike::adresaSpiska(VrstaPrilike::Praksa, Grupa::Studenti), array_values(array_filter($vrste, fn (array $d) => $d[0] === 'Praksa'))[0][1]);
        $studenti = array_values(array_filter($grupe, fn (array $d) => $d[0] === 'Studenti'))[0];
        $romi = array_values(array_filter($grupe, fn (array $d) => $d[0] === 'Romi i Romkinje'))[0];
        $this->assertTrue($studenti[2]);
        $this->assertSame(PrikazPrilike::adresaSpiska(VrstaPrilike::Posao, null), $studenti[1]);
        $this->assertSame(PrikazPrilike::adresaSpiska(VrstaPrilike::Posao, Grupa::Romi), $romi[1]);
        // Red vrsta je i dalje aktivan na „Posao".
        $this->assertSame(['Posao'], array_column(array_filter($vrste, fn (array $d) => $d[2]), 0));
    }

    #[Test]
    public function adresa_spiska_ima_vrstu_pre_grupe_a_bez_njih_je_obican_spisak(): void
    {
        $this->assertSame(route('prilike.index'), PrikazPrilike::adresaSpiska());
        $this->assertSame(route('prilike.index', ['vrsta' => 'posao']), PrikazPrilike::adresaSpiska(VrstaPrilike::Posao));
        $this->assertSame(route('prilike.index', ['grupa' => 'romi']), PrikazPrilike::adresaSpiska(null, Grupa::Romi));
        $this->assertSame(route('prilike.index').'?vrsta=posao&grupa=romi', PrikazPrilike::adresaSpiska(VrstaPrilike::Posao, Grupa::Romi));
    }

    // Filter radi i na početnoj strani, jer je to isti spisak.
    #[Test]
    public function pocetna_strana_ima_isti_red(): void
    {
        $this->sveGrupe();

        $html = $this->get(route('pocetna', ['grupa' => 'osnovci']))->assertOk()->getContent();

        $this->assertSame(['Za osnovci'], $this->naslovi($html));
        $this->assertStringContainsString('href="'.PrikazPrilike::adresaSpiska(null, Grupa::Romi).'"', $html);
    }

    // Bez ijedne javne prilike sa grupom nema ni reda „Ja sam…".
    #[Test]
    public function bez_grupa_u_javnim_prilikama_nema_reda(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Bez grupe']);
        Prilika::factory()->zaGrupe(Grupa::Studenti)->create(['status' => StatusPrilike::Nacrt]);

        $html = $this->get(route('prilike.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('filteri grupe', $html);
        $this->assertStringNotContainsString('Ja sam…', $this->vidljivTekst($html));
        $this->assertSame(['Bez grupe'], $this->naslovi($html));
    }

    // Red „Ja sam…" postoji samo na spisku prilika.
    #[Test]
    public function red_postoji_samo_na_spisku_prilika(): void
    {
        $prilika = Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create();

        foreach ([route('prilike.show', $prilika->slug), route('vodici.index'), route('organizacije.index'), route('pomocnik.index')] as $adresa) {
            $this->assertStringNotContainsString('filteri grupe', $this->get($adresa)->getContent(), $adresa);
        }
    }
}
