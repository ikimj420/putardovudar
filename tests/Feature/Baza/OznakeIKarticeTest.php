<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\PrikazPocetne;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57 T7: oznaka vrste u svojoj boji, „Zatvara se za N dana" (računa kod iz roka) i traka „Vodiči" na početnoj.
#[Group('baza')]
class OznakeIKarticeTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 15, 30, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{0: string|null, 1: bool, 2: string|null}> rok, stalno otvoreno, očekivana oznaka */
    public static function rokovi(): array
    {
        return [
            'danas' => ['2026-10-10', false, 'Zatvara se danas'],
            'sutra' => ['2026-10-11', false, 'Zatvara se za 1 dan'],
            'za dva dana' => ['2026-10-12', false, 'Zatvara se za 2 dana'],
            'za deset dana' => ['2026-10-20', false, 'Zatvara se za 10 dana'],
            'za dvadeset jedan dan' => ['2026-10-31', false, 'Zatvara se za 21 dan'],
            'za dvadeset dva dana' => ['2026-11-01', false, 'Zatvara se za 22 dana'],
            'za trideset šest dana' => ['2026-11-15', false, 'Zatvara se za 36 dana'],
            'bez roka' => [null, false, null],
            'stalno otvoreno' => ['2026-11-15', true, null],
            'stalno otvoreno bez roka' => [null, true, null],
            'rok prošao' => ['2026-10-09', false, null],
        ];
    }

    #[Test]
    #[DataProvider('rokovi')]
    public function oznaka_zatvaranja_se_racuna_iz_roka(?string $rok, bool $stalno, ?string $ocekivano): void
    {
        $prilika = Prilika::factory()->objavljena()->make(['rok' => $rok, 'rok_stalno_otvoren' => $stalno]);

        $this->assertSame($ocekivano, PrikazPrilike::zatvaraSe($prilika));
    }

    // Dan se računa po zoni aplikacije: rok „danas" u 23:59 po Beogradu i dalje je danas, a sutra počinje u ponoć.
    #[Test]
    public function dan_se_racuna_po_beogradskom_vremenu(): void
    {
        $prilika = Prilika::factory()->objavljena()->make(['rok' => '2026-10-10']);

        $this->travelTo(Carbon::create(2026, 10, 10, 23, 59, 0, 'Europe/Belgrade'));
        $this->assertSame('Zatvara se danas', PrikazPrilike::zatvaraSe($prilika));

        $this->travelTo(Carbon::create(2026, 10, 11, 0, 1, 0, 'Europe/Belgrade'));
        $this->assertNull(PrikazPrilike::zatvaraSe($prilika), 'sutradan je rok prošao');
    }

    #[Test]
    public function kartica_i_strana_prilike_nose_oznaku_vrste_i_oznaku_zatvaranja_samo_kad_je_ima(): void
    {
        $sa = Prilika::factory()->objavljena()->create(['naslov' => 'Sa rokom', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-10-15']);
        $bez = Prilika::factory()->objavljena()->create(['naslov' => 'Bez roka', 'vrsta' => VrstaPrilike::Posao, 'rok' => null]);
        $stalno = Prilika::factory()->objavljena()->create(['naslov' => 'Stalno', 'vrsta' => VrstaPrilike::Obuka, 'rok' => null, 'rok_stalno_otvoren' => true]);

        foreach ([route('prilike.index'), route('pocetna')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertStringContainsString('<span class="oznaka" data-vrsta="stipendija">Stipendija</span>', $html, $adresa);
            $this->assertStringContainsString('<span class="oznaka" data-vrsta="posao">Posao</span>', $html, $adresa);
            $this->assertStringContainsString('<span class="oznaka" data-vrsta="obuka">Obuka</span>', $html, $adresa);
            // Samo prilika sa rokom ima oznaku zatvaranja. Na početnoj je ta prilika i u delu „Zatvara se uskoro" (paket 61) i u delu „Prilike", pa ima dve kartice.
            $this->assertSame($adresa === route('pocetna') ? 2 : 1, substr_count($html, 'class="oznaka oznaka-rok"'), $adresa);
            $this->assertStringContainsString('<span class="oznaka oznaka-rok">Zatvara se za 5 dana</span>', $html, $adresa);
        }

        $strana = $this->get(route('prilike.show', $sa->slug))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="oznaka" data-vrsta="stipendija">Stipendija</span>', $strana);
        $this->assertStringContainsString('<span class="oznaka oznaka-rok">Zatvara se za 5 dana</span>', $strana);
        // Ogledalo: prilika bez roka i stalno otvorena na svojoj strani nemaju oznaku.
        foreach ([$bez, $stalno] as $prilika) {
            $this->assertStringNotContainsString('oznaka-rok', $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent(), $prilika->naslov);
        }
    }

    // Stil: četiri vrste imaju svoju boju (konkurs ljubičasta, stipendija bobica, posao narandžasta, obuka zlato), praksa i drugo ostaju pesak; svaki par ima kontrast 4,5 : 1.
    #[Test]
    public function stil_daje_vrstama_boje_sa_kontrastom_a_praksi_i_drugom_pesak(): void
    {
        $stil = $this->stilBezKomentara();

        foreach (['konkurs', 'stipendija', 'posao', 'obuka'] as $vrsta) {
            $this->assertMatchesRegularExpression('/\n\.oznaka\[data-vrsta="'.$vrsta.'"\] \{\s*background: var\(--'.$vrsta.'-pozadina\);\s*color: var\(--'.$vrsta.'-tekst\);/', $stil, $vrsta);
            $this->assertGreaterThanOrEqual(4.5, $this->kontrast($this->hex($vrsta.'-tekst'), $this->hex($vrsta.'-pozadina')), $vrsta);
        }
        $this->assertMatchesRegularExpression('/\n\.oznaka-rok \{\s*background: var\(--posao-pozadina\);\s*color: var\(--posao-tekst\);/', $stil);
        // Praksa i drugo nemaju svoju boju: osnovna oznaka je pesak sa tamnim slovima.
        foreach (['praksa', 'drugo'] as $vrsta) {
            $this->assertStringNotContainsString('.oznaka[data-vrsta="'.$vrsta.'"]', $stil, $vrsta);
        }
        $this->assertMatchesRegularExpression('/\n\.oznaka \{[^}]*background: var\(--pesak\);[^}]*color: #3b2a52;/', $stil);
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast('#3b2a52', $this->hex('pesak')));
        // Ogledalo: sirova narandžasta slova na svetloj podlozi ne bi prošla.
        $this->assertLessThan(4.5, $this->kontrast($this->hex('narandzasta'), $this->hex('posao-pozadina')));
    }

    #[Test]
    public function traka_vodici_je_na_pocetnoj_samo_kad_ima_objavljen_vodic(): void
    {
        $prazno = $this->get(route('pocetna'))->assertOk()->getContent();
        $this->assertStringNotContainsString('vodici-traka', $prazno);

        Vodic::factory()->create(['status' => StatusObjave::Nacrt]);
        $this->assertStringNotContainsString('vodici-traka', $this->get(route('pocetna'))->getContent(), 'nacrt ne otvara traku');

        Vodic::factory()->objavljen()->create();
        $html = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<section class="vodici-traka" aria-labelledby="vodici-traka-naslov">(.*?)</section>#s', $html, $traka));
        $this->assertStringContainsString('<div class="traka-natpis">'.PrikazPocetne::TRAKA_NATPIS.'</div>', $traka[1]);
        $this->assertStringContainsString('<h2 id="vodici-traka-naslov">'.PrikazPocetne::TRAKA_NASLOV.'</h2>', $traka[1]);
        $this->assertStringContainsString(PrikazPocetne::TRAKA_OPIS, html_entity_decode($traka[1]));
        $this->assertStringContainsString('<a class="dugme dugme-glavno dugme-malo" href="'.route('vodici.index').'">'.PrikazPocetne::TRAKA_DUGME.'</a>', $traka[1]);
        // Traka je posle prilika, ne pre njih.
        $prilike = strpos($html, 'id="prilike-naslov"');
        $vodici = strpos($html, 'class="vodici-traka"');
        $this->assertNotFalse($prilike);
        $this->assertNotFalse($vodici);
        $this->assertGreaterThan($prilike, $vodici);
    }

    #[Test]
    public function stil_traki_daje_ljubicastu_podlogu_i_dugme_manje_od_glavnog(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.vodici-traka \{[^}]*border-radius: 26px;[^}]*var\(--ljubicasta\);/s', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-malo \{\s*min-height: 42px;\s*padding: 0 18px;\s*font-size: 15px;/', $stil);
        $this->assertGreaterThanOrEqual(4.5, $this->kontrast('#f3ecff', $this->hex('ljubicasta')));
    }

    // Spisak prilika je u tri kolone na širokom ekranu (kao u predlogu).
    #[Test]
    public function spisak_prilika_je_u_tri_kolone(): void
    {
        Prilika::factory()->objavljena()->create(['rok' => '2026-11-15']);

        $this->assertStringContainsString('<div class="spisak tri">', $this->get(route('prilike.index'))->assertOk()->getContent());
    }

    private function hex(string $naziv): string
    {
        preg_match('/--'.preg_quote($naziv, '/').': (#[0-9a-f]{6});/', $this->stilBezKomentara(), $nalaz);

        return $nalaz[1] ?? '';
    }

    private function osvetljenost(string $boja): float
    {
        [$r, $g, $b] = array_map(fn (string $c) => (hexdec($c) / 255.0) <= 0.03928 ? (hexdec($c) / 255.0) / 12.92 : (((hexdec($c) / 255.0) + 0.055) / 1.055) ** 2.4, str_split(ltrim($boja, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function kontrast(string $prva, string $druga): float
    {
        return (max($this->osvetljenost($prva), $this->osvetljenost($druga)) + 0.05) / (min($this->osvetljenost($prva), $this->osvetljenost($druga)) + 0.05);
    }
}
