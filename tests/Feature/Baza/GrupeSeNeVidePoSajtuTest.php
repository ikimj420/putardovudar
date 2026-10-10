<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 54: grupe se ne vide na sajtu (nema reda „Ja sam…" ni natpisa „Kome je namenjeno"), ostaju u bazi, u adminu, u obradi i u pomoćniku.
// Ovo je prepisan FilterPrilikaPoGrupiTest (paketi 42 i 46): tvrdnje o redu dugmadi sada mere da reda nema, a adresa sa grupom daje isto što i bez nje.
#[Group('baza')]
class GrupeSeNeVidePoSajtuTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

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

    /** @return list<string> nazivi dugmadi u redu vrsta */
    private function dugmad(string $html): array
    {
        preg_match('#<nav class="filteri"[^>]*>(.*?)</nav>#s', $html, $filteri);
        preg_match_all('#<a class="filter-pilula[^"]*" href="[^"]+"[^>]*>([^<]+)</a>#', $filteri[1] ?? '', $nalazi);

        return $nalazi[1];
    }

    /** @return array<string, array{0: string}> */
    public static function adreseSaGrupom(): array
    {
        $grupe = array_combine(array_map(fn (Grupa $g) => $g->value, Grupa::cases()), array_map(fn (Grupa $g) => [$g->value], Grupa::cases()));

        return [
            ...$grupe,
            'izmišljena' => ['evropljani'], 'prazna' => [''], 'velika slova' => ['STUDENTI'], 'broj' => ['1'], 'napad' => ['studenti\' OR 1=1 --'], 'vrsta umesto grupe' => ['posao'],
        ];
    }

    // Ni na spisku ni na početnoj nema reda „Ja sam…", ni natpisa, ni mesta za njega; na spisku je red vrsta (merilo nije prazno), a početna ima najviše šest kartica.
    #[Test]
    public function spisak_i_pocetna_nemaju_red_ja_sam_a_red_vrsta_ostaje(): void
    {
        $this->sveGrupe();

        foreach ([route('prilike.index') => count(Grupa::cases()), route('pocetna') => 6] as $adresa => $kartica) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertSame($adresa === route('pocetna') ? [] : ['Sve prilike', 'Posao'], $this->dugmad($html), $adresa);
            $this->assertCount($kartica, $this->naslovi($html), $adresa);
            foreach (['Ja sam', 'filteri grupe', 'ja-sam', 'filteri-naslov', 'adresa spiska'] as $trag) {
                $this->assertStringNotContainsString($trag, $html, $adresa.': '.$trag);
            }
            foreach (Grupa::cases() as $grupa) {
                $this->assertStringNotContainsString('grupa='.$grupa->value, $html, $adresa);
            }
        }
    }

    // „?grupa=" iz starih veza se ne čita: daje iste prilike kao adresa bez njega (ogledalo: svih devet ima, a nijedna nije izostavljena).
    #[Test]
    #[DataProvider('adreseSaGrupom')]
    public function adresa_sa_grupom_daje_isto_sto_i_bez_nje(string $grupa): void
    {
        $this->sveGrupe();

        $bez = $this->naslovi($this->get(route('prilike.index'))->assertOk()->getContent());
        $sa = $this->naslovi($this->get(route('prilike.index').'?grupa='.rawurlencode($grupa))->assertOk()->getContent());

        $this->assertCount(count(Grupa::cases()), $bez);
        $this->assertSame($bez, $sa, $grupa);
        $this->assertSame($bez, $this->naslovi($this->get(route('prilike.index').'?grupa[]=studenti&grupa[]=romi')->assertOk()->getContent()));
    }

    // Vrsta se i dalje bira; grupa uz nju ne sužava ništa.
    #[Test]
    public function izbor_po_vrsti_radi_a_grupa_u_adresi_ga_ne_menja(): void
    {
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Posao za studente', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Romi)->create(['naslov' => 'Posao za Rome', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-16']);
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Praksa za studente', 'vrsta' => VrstaPrilike::Praksa, 'rok' => '2026-12-17']);

        $this->assertSame(['Posao za studente', 'Posao za Rome'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao']))->assertOk()->getContent()));
        $this->assertSame(['Posao za studente', 'Posao za Rome'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao', 'grupa' => 'romi']))->assertOk()->getContent()));
        $this->assertSame(['Praksa za studente'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'praksa', 'grupa' => 'romi']))->assertOk()->getContent()));
        $this->assertSame(['Sve prilike', 'Posao', 'Praksa'], $this->dugmad($this->get(route('prilike.index', ['grupa' => 'romi']))->getContent()));
    }

    // Kartica, strana prilike i kalendar ne pišu „Kome je namenjeno" ni nazive grupa, ma koliko grupa prilika imala.
    #[Test]
    public function kartica_strana_prilike_i_kalendar_ne_pisu_grupe(): void
    {
        $prilika = Prilika::factory()->objavljena()->zaGrupe(...Grupa::cases())->create(['naslov' => 'Za sve grupe', 'rok' => '2026-12-15']);
        KalendarStavka::factory()->objavljena()->zaGrupe(Grupa::Romi, Grupa::Osnovci)->create(['naziv' => 'Stavka sa grupama', 'otvoreno_do' => '2026-01-26']);

        foreach ([route('prilike.index'), route('prilike.show', $prilika->slug), route('kalendar.index')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();
            // Samo glavni deo strane: meni ima „Organizacije", što je i naziv jedne grupe.
            preg_match('#<main>(.*)</main>#s', $html, $glavni);
            $tekst = $this->vidljivTekst($glavni[1] ?? '');

            // Dokaz da je strana obiđena: piše ono što piše i bez grupa.
            $this->assertStringContainsString($adresa === route('kalendar.index') ? 'Stavka sa grupama' : 'Za sve grupe', $tekst, $adresa);
            $this->assertStringNotContainsString(PrikazPrilike::KOME, $tekst, $adresa);
            $this->assertStringNotContainsString('meta-stavka grupe', $html, $adresa);
            foreach (Grupa::cases() as $grupa) {
                $this->assertStringNotContainsString($grupa->getLabel(), $tekst, $adresa.': '.$grupa->value);
            }
        }
    }

    // Grupe ostaju u podacima: opseg po grupi radi (njega koristi pomoćnik), a tekst „Kome je namenjeno" i dalje postoji za admina i pomoćnika.
    #[Test]
    public function grupe_ostaju_u_podacima_i_u_opsegu(): void
    {
        Prilika::factory()->objavljena()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Javna', 'rok' => '2026-12-15']);
        Prilika::factory()->zaGrupe(Grupa::Studenti)->create(['naslov' => 'Nacrt', 'status' => StatusPrilike::Nacrt, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Bez grupe', 'rok' => '2026-12-15']);

        $this->assertSame(2, Prilika::query()->zaGrupu(Grupa::Studenti)->count());
        $this->assertSame(['Javna'], Prilika::javne()->zaGrupu(Grupa::Studenti)->pluck('naslov')->all());
        $this->assertSame('Studenti', PrikazPrilike::grupe(Prilika::query()->where('naslov', 'Javna')->firstOrFail()));
        $this->assertSame('Kome je namenjeno', PrikazPrilike::KOME);
    }

    #[Test]
    public function adresa_spiska_ima_samo_vrstu_a_bez_nje_je_obican_spisak(): void
    {
        $this->assertSame(route('prilike.index'), PrikazPrilike::adresaSpiska());
        $this->assertSame(route('prilike.index', ['vrsta' => 'posao']), PrikazPrilike::adresaSpiska(VrstaPrilike::Posao));
        $this->assertSame([], (new \ReflectionMethod(PrikazPrilike::class, 'adresaSpiska'))->getNumberOfParameters() > 1 ? ['prima grupu'] : []);
    }

    // Stil za red „Ja sam…" i za pilulu sa grupama više ne postoji (ostao bi mrtav, bez ijedne greške u ispisu).
    #[Test]
    public function stil_nema_pravila_za_red_ja_sam_ni_za_pilulu_sa_grupama(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertStringContainsString('.filter-pilula {', $stil);
        foreach (['.filteri.grupe', '.filteri-naslov', '.meta-stavka.grupe'] as $pravilo) {
            $this->assertStringNotContainsString($pravilo, $stil, $pravilo);
        }
    }
}
