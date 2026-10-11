<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\PrikazPocetne;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 61 T2: početna ima deo „Zatvara se uskoro": najviše 6 kartica, najbliži rok prvi, samo javne prilike sa rokom od danas do danas + 14; bez ijedne dela nema.
#[Group('baza')]
class ZatvaraSeUskoroPocetnaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function prilika(string $naslov, ?string $rok, array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...['naslov' => $naslov, 'rok' => $rok, 'vrsta' => VrstaPrilike::Posao], ...$dopuna]);
    }

    private function deo(string $html): string
    {
        preg_match('#<section aria-labelledby="uskoro-naslov">(.*?)</section>#s', $html, $nalaz);

        return $nalaz[1] ?? '';
    }

    private function deoPrilike(string $html): string
    {
        preg_match('#<section aria-labelledby="prilike-naslov">(.*?)</section>#s', $html, $nalaz);

        return $nalaz[1] ?? '';
    }

    /** @return list<string> */
    private function naslovi(string $html): array
    {
        preg_match_all('#<article class="kartica redosled">\s*<h2><a [^>]*>([^<]+)</a></h2>#', $html, $nalazi);

        return $nalazi[1];
    }

    #[Test]
    public function deo_pokazuje_samo_javne_prilike_sa_rokom_do_cetrnaest_dana_najblizi_prvi(): void
    {
        $this->prilika('Za 14 dana', '2026-10-24');
        $this->prilika('Za 2 dana', '2026-10-12');
        $this->prilika('Danas', '2026-10-10');
        $this->prilika('Za 7 dana', '2026-10-17');
        // Ne ulaze: iza opsega, prošao rok, bez roka, stalno otvoreno, nacrt i arhivirano.
        $this->prilika('Za 15 dana', '2026-10-25');
        $this->prilika('Prošao', '2026-10-09');
        $this->prilika('Bez roka', null);
        $this->prilika('Stalno otvoreno', '2026-10-15', ['rok_stalno_otvoren' => true]);
        $this->prilika('Nacrt', '2026-10-15', ['status' => StatusPrilike::Nacrt]);
        $this->prilika('Arhivirano', '2026-10-15', ['status' => StatusPrilike::Arhivirano]);

        DB::enableQueryLog();
        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        $deo = $this->deo($html);

        $this->assertSame(['Danas', 'Za 2 dana', 'Za 7 dana', 'Za 14 dana'], $this->naslovi($deo));
        // Redosled mora da daje sam upit: indeks po roku bi ga inače slučajno poklopio (ogledalo: upit bez „order by" ne meri ništa).
        $upit = collect(DB::getQueryLog())->pluck('query')->first(fn (string $sql) => str_contains($sql, ' between '));
        $this->assertNotNull($upit);
        $this->assertStringContainsString('order by', $upit);
        $this->assertStringContainsString('<h2 class="naslov-izvora" id="uskoro-naslov">'.PrikazPrilike::USKORO.'</h2>', $deo);
        // Ogledalo: ostale javne prilike su i dalje na početnoj, u svom delu, a van dela se ne pojavljuju nejavne.
        $this->assertContains('Za 15 dana', $this->naslovi($html));
        $this->assertNotContains('Nacrt', $this->naslovi($html));
        $this->assertNotContains('Arhivirano', $this->naslovi($html));
    }

    #[Test]
    public function deo_ima_najvise_sest_kartica_najblizih_po_roku(): void
    {
        foreach (range(1, 8) as $i) {
            $this->prilika('Rok '.$i, today()->addDays($i)->toDateString());
        }

        $naslovi = $this->naslovi($this->deo($this->get(route('pocetna'))->assertOk()->getContent()));

        $this->assertSame(['Rok 1', 'Rok 2', 'Rok 3', 'Rok 4', 'Rok 5', 'Rok 6'], $naslovi);
        // Ogledalo: sedma i osma postoje u bazi i ulaze u opseg, ali ne staju u deo.
        $this->assertCount(8, Prilika::javne()->rokUskoro()->get());
    }

    // Paket 64 T4: ono što je u delu „Zatvara se uskoro" se ne ponavlja u delu „Prilike".
    #[Test]
    public function prilika_iz_dela_uskoro_se_ne_ponavlja_u_delu_prilike(): void
    {
        $this->prilika('Uskoro prva', '2026-10-12');
        $this->prilika('Uskoro druga', '2026-10-20');
        $this->prilika('Daleko', '2026-12-01');
        $this->prilika('Bez roka', null);
        $this->prilika('Stalno', null, ['rok_stalno_otvoren' => true]);

        $html = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertSame(['Uskoro prva', 'Uskoro druga'], $this->naslovi($this->deo($html)));
        $this->assertSame(['Daleko', 'Bez roka', 'Stalno'], $this->naslovi($this->deoPrilike($html)));
        // Ogledalo: svaka prilika je na strani tačno jednom, a nijedna javna nije izgubljena.
        $sve = $this->naslovi($html);
        $this->assertCount(5, $sve);
        $this->assertSame($sve, array_values(array_unique($sve)));
    }

    // Deo „Prilike" i dalje ima do šest kartica: preskočene se ne računaju u njih, a ostale ulaze na njihovo mesto.
    #[Test]
    public function deo_prilike_posle_preskakanja_i_dalje_ima_sest_kartica(): void
    {
        foreach (range(1, 6) as $i) {
            $this->prilika('Uskoro '.$i, today()->addDays($i)->toDateString());
        }
        foreach (range(1, 8) as $i) {
            $this->prilika('Kasnije '.$i, today()->addDays(30 + $i)->toDateString());
        }

        $html = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertCount(6, $this->naslovi($this->deo($html)));
        $this->assertSame(['Kasnije 1', 'Kasnije 2', 'Kasnije 3', 'Kasnije 4', 'Kasnije 5', 'Kasnije 6'], $this->naslovi($this->deoPrilike($html)));
    }

    // Kad se uskoro zatvara više od šest: ono što deo ne pokazuje nije sakriveno, ide u deo „Prilike" (preskaču se samo prikazane).
    #[Test]
    public function prilike_koje_ne_staju_u_deo_uskoro_ostaju_u_delu_prilike(): void
    {
        foreach (range(1, 8) as $i) {
            $this->prilika('Rok '.$i, today()->addDays($i)->toDateString());
        }

        $html = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertSame(['Rok 1', 'Rok 2', 'Rok 3', 'Rok 4', 'Rok 5', 'Rok 6'], $this->naslovi($this->deo($html)));
        $this->assertSame(['Rok 7', 'Rok 8'], $this->naslovi($this->deoPrilike($html)));
    }

    // Dugme ka celom izboru stoji samo kad se zatvara više od šest; sa tačno šest nema šta da se doda.
    #[Test]
    public function dugme_sve_koje_se_zatvaraju_uskoro_stoji_samo_kad_ih_je_vise_od_sest(): void
    {
        // Tekst je Cowork-ov (paket 64 T4), ne konstanta koja se poredi sama sa sobom.
        $this->assertSame('Sve koje se zatvaraju uskoro', PrikazPocetne::SVE_USKORO_DUGME);
        $adresa = route('prilike.index', ['uskoro' => '1']);
        $dugme = '<a class="dugme dugme-glavno" href="'.$adresa.'">'.PrikazPocetne::SVE_USKORO_DUGME.'</a>';

        foreach (range(1, 6) as $i) {
            $this->prilika('Rok '.$i, today()->addDays($i)->toDateString());
        }
        $sest = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertStringNotContainsString(PrikazPocetne::SVE_USKORO_DUGME, $sest);
        $this->assertStringNotContainsString('?uskoro=1', $sest);

        $this->prilika('Rok 7', today()->addDays(7)->toDateString());
        $sedam = $this->get(route('pocetna'))->assertOk()->getContent();
        $deo = $this->deo($sedam);

        // Dugme je u delu „Zatvara se uskoro", posle kartica, a ne u delu „Prilike" (tamo je „Sve prilike").
        $this->assertStringContainsString($dugme, $deo);
        $this->assertGreaterThan(strrpos($deo, '</article>'), strpos($deo, $dugme));
        $this->assertStringNotContainsString($adresa, $this->deoPrilike($sedam));
        // Ogledalo: dugme „Sve prilike" ostaje i vodi na običan spisak.
        $this->assertStringContainsString('<a class="dugme dugme-glavno" href="'.route('prilike.index').'">'.PrikazPocetne::SVE_PRILIKE_DUGME.'</a>', $this->deoPrilike($sedam));
    }

    // Dugme ne obećava ono čega nema: adresa kojom vodi pokazuje svih sedam, a početna samo šest.
    #[Test]
    public function dugme_vodi_na_spisak_sa_svim_prilikama_koje_se_zatvaraju_uskoro(): void
    {
        foreach (range(1, 7) as $i) {
            $this->prilika('Rok '.$i, today()->addDays($i)->toDateString());
        }
        $this->prilika('Daleko', '2026-12-01');

        $spisak = $this->get(route('prilike.index', ['uskoro' => '1']))->assertOk()->getContent();

        $this->assertSame(array_map(fn (int $i) => 'Rok '.$i, range(1, 7)), $this->naslovi($spisak));
    }

    #[Test]
    public function bez_prilike_koja_se_zatvara_uskoro_dela_nema_ni_naslova(): void
    {
        $this->prilika('Daleko', '2026-12-01');
        $this->prilika('Bez roka', null);
        $this->prilika('Stalno', null, ['rok_stalno_otvoren' => true]);

        $html = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertStringNotContainsString('uskoro-naslov', $html);
        $this->assertStringNotContainsString(PrikazPrilike::USKORO, $this->vidljivTekst($html));
        $this->assertSame('', $this->deo($html));
        // Ogledalo: čim jedna prilika uđe u opseg, deo i naslov se pojavljuju.
        $this->prilika('Uskoro', '2026-10-20');

        $this->assertStringContainsString(PrikazPrilike::USKORO, $this->vidljivTekst($this->get(route('pocetna'))->getContent()));
    }

    #[Test]
    public function deo_stoji_posle_plocica_pre_prilika_i_pre_trake_vodici(): void
    {
        $this->prilika('Uskoro', '2026-10-20');
        Vodic::factory()->objavljen()->create();

        $html = $this->get(route('pocetna'))->assertOk()->getContent();
        $plocice = strpos($html, '<ul class="plocice"');
        $uskoro = strpos($html, 'id="uskoro-naslov"');
        $prilike = strpos($html, 'id="prilike-naslov"');
        $traka = strpos($html, 'class="vodici-traka"');

        $this->assertNotFalse($plocice);
        $this->assertNotFalse($uskoro);
        $this->assertNotFalse($prilike);
        $this->assertNotFalse($traka);
        $this->assertLessThan($uskoro, $plocice);
        $this->assertLessThan($prilike, $uskoro);
        $this->assertLessThan($traka, $uskoro);
    }

    // Kartice su iste kao svuda: veza ka prilici, oznaka vrste i „Zatvara se za N dana" / „Zatvara se danas".
    #[Test]
    public function kartice_u_delu_imaju_oznaku_vrste_i_koliko_je_dana_ostalo(): void
    {
        $danas = $this->prilika('Danas', '2026-10-10', ['vrsta' => VrstaPrilike::Konkurs]);
        $kasnije = $this->prilika('Za pet dana', '2026-10-15', ['vrsta' => VrstaPrilike::Obuka]);

        $deo = $this->deo($this->get(route('pocetna'))->assertOk()->getContent());

        $this->assertSame(['Danas', 'Za pet dana'], $this->naslovi($deo));
        $this->assertStringContainsString('href="'.route('prilike.show', $danas->slug).'"', $deo);
        $this->assertStringContainsString('<span class="oznaka" data-vrsta="konkurs">Konkurs</span>', $deo);
        $this->assertStringContainsString('<span class="oznaka" data-vrsta="obuka">Obuka</span>', $deo);
        $this->assertStringContainsString('<span class="oznaka oznaka-rok">'.PrikazPrilike::ZATVARA_SE_DANAS.'</span>', $deo);
        $this->assertStringContainsString('<span class="oznaka oznaka-rok">Zatvara se za 5 dana</span>', $deo);
        $this->assertSame('Zatvara se za 5 dana', PrikazPrilike::zatvaraSe($kasnije));
    }

    // Dva odeljka sa spiskom jedan za drugim ne smeju da se lepe: naslov odeljka koji sledi odeljku ima isti razmak kao naslov posle spiska.
    #[Test]
    public function naslov_odeljka_posle_odeljka_ima_razmak_od_trideset_piksela(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\nsection \+ section > \.naslov-izvora \{\s*margin-top: 30px;\s*\}/', $stil);
        $this->assertMatchesRegularExpression('/\n\.spisak \+ \.naslov-izvora \{\s*margin-top: 30px;\s*\}/', $stil);
        $this->assertMatchesRegularExpression('/\n\.naslov-izvora \{\s*margin: 8px 0 14px;/', $stil);
    }

    // „Danas" za deo je po Beogradu: posle ponoći po Beogradu juče više nije u opsegu, a dan koji je 14 dana unapred jeste.
    #[Test]
    public function granice_dela_se_racunaju_od_beogradskog_danas(): void
    {
        foreach (['2026-10-10', '2026-10-11', '2026-10-25', '2026-10-26'] as $rok) {
            $this->prilika('Rok '.$rok, $rok);
        }

        $this->travelTo(Carbon::parse('2026-10-10 22:30:00', 'UTC'));

        $this->assertSame(['Rok 2026-10-11', 'Rok 2026-10-25'], $this->naslovi($this->deo($this->get(route('pocetna'))->assertOk()->getContent())));
    }
}
