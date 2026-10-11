<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\BojeVrata;
use Tests\Concerns\StilJavnihStrana;

// Paket 62/T4: oznake u svetlu vrata su pravi linkovi ka /prilike?vrsta=…, samo tekst (bez pozadine i strelice), tamna slova, tačka u boji vrste, bar 44 px visoke.
#[Group('baza')]
class OznakeUVratimaTest extends BazaTestCase
{
    use BojeVrata, RefreshDatabase, StilJavnihStrana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function prilika(VrstaPrilike $vrsta, array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...['vrsta' => $vrsta, 'rok' => '2026-12-15', 'naslov' => 'Prilika '.$vrsta->value], ...$dopuna]);
    }

    /** @return list<array{0: string, 1: string}> adresa i naziv svake oznake u svetlu, redom */
    private function oznake(string $html): array
    {
        preg_match('#<nav class="u-svetlu"[^>]*>(.*?)</nav>#s', $html, $nav);
        preg_match_all('#<a class="vrsta-veza" href="([^"]+)"><span class="vrsta-tacka" data-vrsta="[a-z]+"></span>([^<]+)</a>#', $nav[1] ?? '', $nalazi, PREG_SET_ORDER);

        return array_map(fn (array $oznaka) => [html_entity_decode($oznaka[1]), $oznaka[2]], $nalazi);
    }

    #[Test]
    public function oznake_su_pravi_linkovi_ka_spisku_prilika_svoje_vrste_a_nav_ima_naziv_za_citac_ekrana(): void
    {
        foreach ([VrstaPrilike::Posao, VrstaPrilike::Stipendija, VrstaPrilike::Konkurs] as $vrsta) {
            $this->prilika($vrsta);
        }

        $html = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertSame([
            [PrikazPrilike::adresaSpiska(VrstaPrilike::Posao), 'Posao'],
            [PrikazPrilike::adresaSpiska(VrstaPrilike::Stipendija), 'Stipendija'],
            [PrikazPrilike::adresaSpiska(VrstaPrilike::Konkurs), 'Konkurs'],
        ], $this->oznake($html));
        $this->assertStringContainsString('<nav class="u-svetlu" aria-label="Vrste prilika">', $html);
        // Slika ostaje ukras (aria-hidden), a oznake nisu u njoj: nema teksta ni pravougaonika u slici.
        preg_match('#<svg class="vrata".*?</svg>#s', $html, $slika);
        $this->assertStringContainsString('aria-hidden="true"', $slika[0]);
        $this->assertStringNotContainsString('<text', $slika[0]);
        $this->assertStringNotContainsString('<rect', $slika[0]);
        $this->assertStringNotContainsString('Posao', $slika[0]);
        $this->assertStringNotContainsString('<nav', $slika[0]);
    }

    // Svaka veza vodi na pravu vrstu: spisak sa tom vrstom ima samo prilike te vrste, a njeno dugme je aktivno.
    #[Test]
    public function svaka_oznaka_vodi_na_spisak_sa_svojom_vrstom(): void
    {
        foreach (VrstaPrilike::cases() as $vrsta) {
            $this->prilika($vrsta);
        }

        $oznake = $this->oznake($this->get(route('pocetna'))->assertOk()->getContent());

        $this->assertCount(count(VrstaPrilike::cases()), $oznake);
        foreach ($oznake as [$adresa, $naziv]) {
            $spisak = $this->get($adresa)->assertOk()->getContent();
            preg_match_all('#<article class="kartica redosled">\s*<h2><a [^>]*>([^<]+)</a></h2>#', $spisak, $kartice);
            $vrsta = VrstaPrilike::from(explode('=', (string) parse_url($adresa, PHP_URL_QUERY))[1]);

            $this->assertSame($vrsta->getLabel(), $naziv);
            $this->assertSame(['Prilika '.$vrsta->value], $kartice[1], $adresa);
            $this->assertMatchesRegularExpression('#<a class="filter-pilula aktivno" href="[^"]+" aria-current="true">'.$naziv.'</a>#', $spisak, $adresa);
        }
    }

    // Ogledalo: vrsta bez javne prilike (nacrt ili prošao rok) nema oznaku, a bez ijedne vrste nema ni nav, dok vrata stoje.
    #[Test]
    public function oznake_su_samo_za_vrste_sa_javnom_prilikom_a_bez_njih_nema_ni_nav(): void
    {
        $this->prilika(VrstaPrilike::Posao);
        $this->prilika(VrstaPrilike::Praksa, ['status' => StatusPrilike::Nacrt]);
        $this->prilika(VrstaPrilike::Obuka, ['rok' => '2026-10-01']);

        foreach ([route('pocetna'), route('prilike.index'), route('vodici.index')] as $adresa) {
            $this->assertSame([[PrikazPrilike::adresaSpiska(VrstaPrilike::Posao), 'Posao']], $this->oznake($this->get($adresa)->assertOk()->getContent()), $adresa);
        }

        Prilika::query()->delete();
        $prazno = $this->get(route('pocetna'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="u-svetlu"', $prazno);
        $this->assertStringContainsString('<svg class="vrata"', $prazno);
    }

    // Stil: samo tekst — bez pozadine, okvira, senke i strelice; tamna slova (noć) 18 px debela, bar 44 px visoka, tamnija boja na prelaz mišem (bez crte), tamni okvir fokusa.
    #[Test]
    public function stil_oznaka_je_samo_tekst_sa_tackom_u_boji_vrste_i_vidljivim_fokusom(): void
    {
        $stil = $this->stilBezKomentara();

        preg_match('/\n\.vrsta-veza \{([^}]*)\}/', $stil, $veza);
        $this->assertNotEmpty($veza);
        foreach (['color: var(--noc);', 'font-size: 18px;', 'font-weight: 700;', 'min-height: 44px;', 'text-decoration: none;'] as $svojstvo) {
            $this->assertStringContainsString($svojstvo, $veza[1]);
        }
        foreach (['background', 'border:', 'box-shadow', 'outline'] as $zabranjeno) {
            $this->assertStringNotContainsString($zabranjeno, $veza[1], $zabranjeno);
        }
        // Bez strelice: nijedno pravilo oznake ne piše sadržaj iza ili ispred.
        $this->assertDoesNotMatchRegularExpression('/\.vrsta-veza[^{]*::(?:after|before)/', $stil);
        $this->assertDoesNotMatchRegularExpression('/\.u-svetlu[^{]*::(?:after|before)/', $stil);
        // Na prelaz mišem menja boju (tamnija „noć"), bez crte (paket 66).
        $this->assertMatchesRegularExpression('/\n\.vrsta-veza:hover \{\s*color: var\(--noc-duboka\);\s*\}/', $stil);
        $this->assertStringNotContainsString('underline', $stil);
        $this->assertMatchesRegularExpression('/\n\.vrsta-veza:focus-visible \{[^}]*outline: 3px solid var\(--noc\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.vrsta-tacka \{[^}]*width: 12px;[^}]*height: 12px;[^}]*box-shadow: 0 0 0 2px rgba\(255, 255, 255, 0\.7\);/', $stil);
        foreach (['posao' => 'narandzasta', 'stipendija' => 'bobica', 'konkurs' => 'ljubicasta', 'obuka' => 'zlato'] as $vrsta => $boja) {
            $this->assertMatchesRegularExpression('/\n\.vrsta-tacka\[data-vrsta="'.$vrsta.'"\] \{\s*background: var\(--'.$boja.'\);/', $stil, $vrsta);
        }
        // Mesto u svetlu: unutar vrata, od 30% visine i 26,5% širine.
        $this->assertMatchesRegularExpression('/\n\.u-svetlu \{[^}]*position: absolute;[^}]*top: 30%;[^}]*left: 26\.5%;/', $stil);
    }

    // Kontrast tamnih slova na svetlu: izmereno u pregledaču (375 i 1280 px, svih šest oznaka) i ovde računom sa istim modelom. Posle paketa 67 (svetlija donja boja
    // svetla na vodičima i organizacijama) je bar 4,5 : 1 na svih pet strana sa vratima, na obe širine, za svih šest oznaka.
    #[Test]
    public function kontrast_je_bar_4_5_na_svih_pet_strana_za_svih_sest_oznaka(): void
    {
        $strane = ['pocetna' => null, 'prilike' => 'prilike', 'kalendar' => 'kalendar', 'vodici' => 'vodici', 'organizacije' => 'organizacije'];
        $proveren = 0;

        foreach ($strane as $ime => $strana) {
            foreach ([343, 380] as $sirina) {
                $kontrasti = $this->kontrasti($strana, $sirina);

                $this->assertCount(6, $kontrasti);
                foreach ($kontrasti as $i => $kontrast) {
                    $this->assertGreaterThanOrEqual(4.5, $kontrast, $ime.' '.$sirina.' px, oznaka '.($i + 1).': '.number_format($kontrast, 2));
                    $proveren++;
                }
            }
        }

        // Neprazan skup: 5 strana × 2 širine × 6 oznaka.
        $this->assertSame(60, $proveren);

        // Ogledalo: model prati pravu boju — najsvetlija strana (kalendar) ima znatno veći kontrast od najtamnije (organizacije) na istoj oznaci.
        $this->assertGreaterThan($this->kontrasti('organizacije', 343)[5] + 4, $this->kontrasti('kalendar', 343)[5]);
        // Izmereno u pregledaču posle paketa 67 (375 px): vodiči peta 5,54, šesta 4,51; organizacije peta 5,27, šesta 4,52 (model se slaže na dve decimale).
        $this->assertEqualsWithDelta(5.54, $this->kontrasti('vodici', 343)[4], 0.01);
        $this->assertEqualsWithDelta(4.51, $this->kontrasti('vodici', 343)[5], 0.01);
        $this->assertEqualsWithDelta(5.27, $this->kontrasti('organizacije', 343)[4], 0.01);
        $this->assertEqualsWithDelta(4.52, $this->kontrasti('organizacije', 343)[5], 0.01);
        // Boje koje merilo čita su one iz stila: donja boja svetla na vodičima i organizacijama.
        $this->assertSame('#9e65f0', $this->bojeVrata('vodici')['svetlo-3']);
        $this->assertSame('#cb5e97', $this->bojeVrata('organizacije')['svetlo-3']);
    }
}
