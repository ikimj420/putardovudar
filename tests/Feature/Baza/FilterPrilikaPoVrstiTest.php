<?php

namespace Tests\Feature\Baza;

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

// Paket 34: filter prilika po vrsti na /prilike; obične veze (?vrsta=), bez skripta, samo kroz Prilika::javne().
#[Group('baza')]
class FilterPrilikaPoVrstiTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** Po jedna javna prilika svake vrste, naslovom „Prilika <vrsta>". */
    private function sveVrste(): void
    {
        foreach (VrstaPrilike::cases() as $vrsta) {
            Prilika::factory()->objavljena()->create(['naslov' => 'Prilika '.$vrsta->value, 'vrsta' => $vrsta, 'rok' => '2026-12-15']);
        }
    }

    /** @return list<string> */
    private function naslovi(string $html): array
    {
        preg_match_all('#<article class="kartica redosled">\s*<h2><a [^>]*>([^<]+)</a></h2>#', $html, $nalazi);

        return $nalazi[1];
    }

    /** @return list<array{0: string, 1: string, 2: bool, 3: bool}> naziv, adresa, klasa „aktivno", aria-current */
    private function dugmad(string $html): array
    {
        preg_match('#<nav class="filteri"[^>]*>(.*?)</nav>#s', $html, $filteri);
        preg_match_all('#<a class="filter-pilula( aktivno)?" href="([^"]+)"( aria-current="true")?>([^<]+)</a>#', $filteri[1] ?? '', $nalazi, PREG_SET_ORDER);

        return array_map(fn (array $dugme) => [$dugme[4], html_entity_decode($dugme[2]), $dugme[1] !== '', $dugme[3] !== ''], $nalazi);
    }

    /** @return array<string, array{0: VrstaPrilike}> */
    public static function vrste(): array
    {
        return array_combine(array_map(fn (VrstaPrilike $v) => $v->value, VrstaPrilike::cases()), array_map(fn (VrstaPrilike $v) => [$v], VrstaPrilike::cases()));
    }

    #[Test]
    #[DataProvider('vrste')]
    public function adresa_sa_vrstom_pokazuje_samo_prilike_te_vrste(VrstaPrilike $vrsta): void
    {
        $this->sveVrste();

        $html = $this->get(route('prilike.index', ['vrsta' => $vrsta->value]))->assertOk()->getContent();

        $this->assertSame(['Prilika '.$vrsta->value], $this->naslovi($html));
        // Ogledalo: bez vrste su sve, sa svakom drugom vrstom ne ostaje ova.
        $this->assertCount(count(VrstaPrilike::cases()), $this->naslovi($this->get(route('prilike.index'))->getContent()));
        $this->assertNotContains('Prilika '.$vrsta->value, $this->naslovi($this->get(route('prilike.index', ['vrsta' => $vrsta === VrstaPrilike::Posao ? 'praksa' : 'posao']))->getContent()));
    }

    #[Test]
    public function dugmad_su_sve_prilike_i_po_jedno_za_svaku_vrstu_sa_javnom_prilikom_obicnim_vezama(): void
    {
        $this->sveVrste();

        $html = $this->get(route('prilike.index'))->assertOk()->getContent();
        $dugmad = $this->dugmad($html);

        $this->assertSame(
            [PrikazPrilike::SVE_PRILIKE, ...array_map(fn (VrstaPrilike $v) => $v->getLabel(), VrstaPrilike::cases())],
            array_map(fn (array $d) => $d[0], $dugmad),
        );
        $this->assertSame(
            [route('prilike.index'), ...array_map(fn (VrstaPrilike $v) => route('prilike.index', ['vrsta' => $v->value]), VrstaPrilike::cases())],
            array_map(fn (array $d) => $d[1], $dugmad),
        );
        $this->assertSame('Sve prilike', $dugmad[0][0]);
        // Grupa dugmadi ima naziv za čitač ekrana (predlog „Brzi filteri", PITANJA-26-38.md).
        $this->assertStringContainsString('<nav class="filteri" aria-label="Brzi filteri">', $html);
        // Bez skripta: nema dugmeta, obrasca ni rukovaoca događaja u dugmadi.
        preg_match('#<nav class="filteri"[^>]*>(.*?)</nav>#s', $html, $filteri);
        $this->assertNotSame('', $filteri[1] ?? '');
        $this->assertStringNotContainsString('<button', $filteri[1]);
        $this->assertStringNotContainsString('onclick', $filteri[1]);
        $this->assertStringNotContainsString('<script', $filteri[1]);
    }

    #[Test]
    public function aktivno_je_samo_dugme_izabrane_vrste_a_bez_vrste_sve_prilike(): void
    {
        $this->sveVrste();

        foreach ([[[], PrikazPrilike::SVE_PRILIKE], [['vrsta' => 'konkurs'], 'Konkurs']] as [$upit, $aktivno]) {
            $dugmad = $this->dugmad($this->get(route('prilike.index', $upit))->getContent());

            // Aktivno je tačno jedno dugme, i klasom i oznakom za čitač ekrana.
            $this->assertSame([$aktivno], array_column(array_filter($dugmad, fn (array $d) => $d[2]), 0), json_encode($upit));
            $this->assertSame([$aktivno], array_column(array_filter($dugmad, fn (array $d) => $d[3]), 0), json_encode($upit));
        }
    }

    // Dugme postoji samo za vrstu koja ima bar jednu javnu priliku; nacrt, arhivirano i isteklo ne računaju.
    #[Test]
    public function vrsta_bez_javne_prilike_nema_dugme_a_nacrt_arhivirano_i_isteklo_ne_ulaze(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Javna posao', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        Prilika::factory()->create(['naslov' => 'Nacrt praksa', 'vrsta' => VrstaPrilike::Praksa, 'status' => StatusPrilike::Nacrt]);
        Prilika::factory()->create(['naslov' => 'Arhivirana obuka', 'vrsta' => VrstaPrilike::Obuka, 'status' => StatusPrilike::Arhivirano]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Istekla stipendija', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-10-01']);

        $dugmad = array_map(fn (array $d) => $d[0], $this->dugmad($this->get(route('prilike.index'))->getContent()));

        $this->assertSame([PrikazPrilike::SVE_PRILIKE, VrstaPrilike::Posao->getLabel()], $dugmad);

        // Ni sa tim vrstama u adresi ne stiže ništa što nije javno: nema dugmeta, pa su to „nepoznate" vrste i daju sve javno.
        foreach (['praksa', 'obuka', 'stipendija'] as $vrsta) {
            $this->assertSame(['Javna posao'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => $vrsta]))->assertOk()->getContent()), $vrsta);
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function nepoznate(): array
    {
        return [
            'izmišljena vrsta' => ['xyz'],
            'prazna' => [''],
            'velika slova' => ['POSAO'],
            'broj' => ['1'],
            'napad' => ['posao\' OR 1=1 --'],
        ];
    }

    #[Test]
    #[DataProvider('nepoznate')]
    public function nepoznata_vrsta_u_adresi_daje_sve_prilike_a_ne_gresku(string $vrednost): void
    {
        $this->sveVrste();

        $html = $this->get(route('prilike.index', ['vrsta' => $vrednost]))->assertOk()->getContent();

        $this->assertCount(count(VrstaPrilike::cases()), $this->naslovi($html));
        $this->assertSame([PrikazPrilike::SVE_PRILIKE], array_column(array_filter($this->dugmad($html), fn (array $d) => $d[2]), 0));
    }

    // Razmaci oko vrednosti se uklanjaju pre kontrolera (TrimStrings), pa „ posao" je „posao".
    #[Test]
    public function razmaci_oko_vrste_se_ignorisu(): void
    {
        $this->sveVrste();

        $this->assertSame(['Prilika posao'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => ' posao ']))->getContent()));
    }

    #[Test]
    public function vrsta_koja_nije_tekst_daje_sve_prilike(): void
    {
        $this->sveVrste();

        $html = $this->get(route('prilike.index').'?vrsta[]=posao&vrsta[]=praksa')->assertOk()->getContent();

        $this->assertCount(count(VrstaPrilike::cases()), $this->naslovi($html));
    }

    // Filter radi i na početnoj strani, jer je to isti spisak; dugmad vode na /prilike.
    #[Test]
    public function pocetna_strana_ima_isti_filter(): void
    {
        $this->sveVrste();

        $html = $this->get(route('pocetna', ['vrsta' => 'obuka']))->assertOk()->getContent();

        $this->assertSame(['Prilika obuka'], $this->naslovi($html));
        $this->assertStringContainsString('href="'.route('prilike.index', ['vrsta' => 'posao']).'"', $html);
    }

    // Redosled po roku ostaje i unutar vrste: najbliži rok prvi, stalno otvorene na kraju.
    #[Test]
    public function unutar_vrste_najblizi_rok_je_prvi_a_stalno_otvorene_na_kraju(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Kasno', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-20']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Stalno', 'vrsta' => VrstaPrilike::Posao, 'rok' => null, 'rok_stalno_otvoren' => true]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Rano', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-11-01']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Druga vrsta', 'vrsta' => VrstaPrilike::Praksa, 'rok' => '2026-10-20']);

        $this->assertSame(['Rano', 'Kasno', 'Stalno'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao']))->getContent()));
    }

    #[Test]
    public function bez_javnih_prilika_nema_dugmadi_osim_sve_prilike_i_stoji_poruka(): void
    {
        $html = $this->get(route('prilike.index'))->assertOk()->getContent();

        $this->assertSame([PrikazPrilike::SVE_PRILIKE], array_map(fn (array $d) => $d[0], $this->dugmad($html)));
        $this->assertStringContainsString(PrikazPrilike::NEMA_PRILIKA, $this->vidljivTekst($html));
    }

    // Nov tekst („Sve prilike", „Brzi filteri") postoji samo na spisku, a ne na ostalim stranama.
    #[Test]
    public function dugmad_postoje_samo_na_spisku_prilika(): void
    {
        $prilika = Prilika::factory()->objavljena()->create();

        foreach ([route('prilike.show', $prilika->slug), route('vodici.index'), route('organizacije.index'), route('pomocnik.index')] as $adresa) {
            $this->assertStringNotContainsString('class="filteri"', $this->get($adresa)->getContent(), $adresa);
        }
    }
}
