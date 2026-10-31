<?php

namespace Tests\Feature\Baza;

use App\Models\KalendarStavka;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 97/T5 (nalaz 11 iz PITANJA-92): /kalendar od javnih prilika učitava samo kolone koje čita (id, slug, naslov, link_izvora), jednim upitom, a strana izgleda isto.
#[Group('baza')]
class KalendarUcitavaSamoPotrebneKoloneTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return list<string> kolone iz svakog upita koji učitava redove tabele prilike (ne i zbirove, kao „distinct vrsta" za zaglavlje strane) */
    private function kolone(callable $posao): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $posao();
        DB::disableQueryLog();

        $nadjeno = [];

        foreach (DB::getQueryLog() as $upit) {
            if (preg_match('/^select (?!distinct |count\(|exists)(.+?) from `prilike`/i', (string) $upit['query'], $nalaz) === 1) {
                $nadjeno[] = str_replace('`', '', $nalaz[1]);
            }
        }

        return $nadjeno;
    }

    private function napraviPodatke(): void
    {
        Prilika::factory()->objavljena()->create(['naslov' => 'Otvorena po linku', 'slug' => 'otvorena-po-linku', 'link_izvora' => 'https://izvor.test/poziv', 'rok' => '2026-12-01', 'opis' => str_repeat('Dug opis. ', 200)]);
        $izabrana = Prilika::factory()->objavljena()->create(['naslov' => 'Izabrana rukom', 'slug' => 'izabrana-rukom', 'link_izvora' => 'https://drugi.test/poziv', 'rok' => '2026-12-01']);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Stavka po linku', 'otvoreno_do' => '2026-01-26', 'link_izvora' => 'https://izvor.test/poziv']);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Stavka izabrana rukom', 'otvoreno_do' => '2026-02-20', 'link_izvora' => 'https://nema-veze.test/staro', 'prilika_id' => $izabrana->getKey()]);
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Stavka bez prilike', 'otvoreno_do' => '2026-03-12', 'link_izvora' => 'https://treci.test/staro']);
    }

    #[Test]
    public function kalendar_od_prilika_uzima_samo_cetiri_kolone_i_samo_jednim_upitom(): void
    {
        $this->napraviPodatke();

        $upiti = $this->kolone(fn () => $this->get(route('kalendar.index'))->assertOk());

        $this->assertSame(['id, slug, naslov, link_izvora'], $upiti);
    }

    // Ogledalo: isti merač vidi „sve kolone" kad ih neki upit stvarno uzima, pa zelen prolaz gore nije „merač ne vidi upit".
    #[Test]
    public function merac_vidi_upit_koji_uzima_sve_kolone(): void
    {
        $this->napraviPodatke();

        $upiti = $this->kolone(fn () => Prilika::javne()->orderBy('id')->get());

        $this->assertSame(['*'], $upiti);
    }

    // Strana izgleda isto kao sa svim kolonama: i veza po linku i veza koju je Ivan izabrao pišu „Sada otvoreno" sa naslovom prilike i vode na njenu stranu; stavka bez prilike ne.
    #[Test]
    public function strana_pise_sada_otvoreno_i_vodi_na_priliku_kao_i_pre(): void
    {
        $this->napraviPodatke();

        $html = (string) $this->get(route('kalendar.index'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('prilike.show', 'otvorena-po-linku').'">Stavka po linku</a>', $html);
        $this->assertStringContainsString('href="'.route('prilike.show', 'izabrana-rukom').'">Stavka izabrana rukom</a>', $html);
        $this->assertStringContainsString('Sada otvoreno: Otvorena po linku', preg_replace('/\s+/', ' ', $html) ?? '');
        $this->assertStringContainsString('Sada otvoreno: Izabrana rukom', preg_replace('/\s+/', ' ', $html) ?? '');
        $this->assertSame(2, substr_count($html, 'Sada otvoreno: '));
        $this->assertStringNotContainsString('route-prilike-show-stavka-bez-prilike', $html);
    }
}
