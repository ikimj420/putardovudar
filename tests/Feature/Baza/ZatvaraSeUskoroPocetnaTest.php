<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Vodic;
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
