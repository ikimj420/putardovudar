<?php

namespace Tests\Feature\Baza;

use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Services\KalendarIzPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 97/T6 (nalaz 12 iz PITANJA-92): izvor je promenio oblik adrese (Bibija, paket 78: http://www. → https://), a kalendar i dalje zna da je to ista strana: „Sada otvoreno" se vidi, a stavka se ne dupla.
#[Group('baza')]
class KalendarIstiLinkBibijaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const STARI_OBLIK = 'http://www.bibija.org.rs/konkurs-za-mlade-romkinje';

    private const NOVI_OBLIK = 'https://bibija.org.rs/konkurs-za-mlade-romkinje/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function kalendar(): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $this->get(route('kalendar.index'))->assertOk()->getContent());
    }

    #[Test]
    public function stavka_sa_starim_oblikom_adrese_dobija_sada_otvoreno_od_prilike_sa_novim(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Konkurs za mlade Romkinje', 'otvoreno_do' => '2025-12-01', 'link_izvora' => self::STARI_OBLIK]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Bibija: konkurs za mlade Romkinje', 'slug' => 'bibija-konkurs', 'link_izvora' => self::NOVI_OBLIK, 'rok' => '2026-12-01']);

        $strana = $this->kalendar();

        $this->assertStringContainsString('Sada otvoreno: Bibija: konkurs za mlade Romkinje', $strana);
        $this->assertStringContainsString('href="'.route('prilike.show', 'bibija-konkurs').'">Konkurs za mlade Romkinje</a>', $strana);
    }

    // Ogledalo: druga strana istog sajta ne dobija „Sada otvoreno".
    #[Test]
    public function druga_strana_istog_sajta_nije_sada_otvoreno(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Konkurs za mlade Romkinje', 'otvoreno_do' => '2025-12-01', 'link_izvora' => self::STARI_OBLIK]);
        Prilika::factory()->objavljena()->create(['naslov' => 'Bibija: drugi poziv', 'link_izvora' => 'https://bibija.org.rs/drugi-poziv/', 'rok' => '2026-12-01']);

        $this->assertStringNotContainsString('Sada otvoreno', $this->kalendar());
    }

    #[Test]
    public function dodaj_u_kalendar_ne_pravi_dupli_stavku_kad_je_izvor_promenio_oblik_adrese(): void
    {
        $postojeca = KalendarStavka::factory()->objavljena()->create(['naziv' => 'Konkurs za mlade Romkinje', 'otvoreno_do' => '2026-09-01', 'link_izvora' => self::STARI_OBLIK]);
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Bibija: konkurs za mlade Romkinje', 'link_izvora' => self::NOVI_OBLIK, 'rok' => '2026-09-01']);

        $vracena = app(KalendarIzPrilike::class)->dodaj($prilika);

        $this->assertSame($postojeca->getKey(), $vracena->getKey());
        $this->assertSame(1, KalendarStavka::query()->count());
    }

    // Ogledalo: druga strana istog sajta sa istim rokom je nova stavka.
    #[Test]
    public function druga_strana_istog_sajta_sa_istim_rokom_je_nova_stavka(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Konkurs za mlade Romkinje', 'otvoreno_do' => '2026-09-01', 'link_izvora' => self::STARI_OBLIK]);
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Bibija: drugi poziv', 'link_izvora' => 'https://bibija.org.rs/drugi-poziv/', 'rok' => '2026-09-01']);

        app(KalendarIzPrilike::class)->dodaj($prilika);

        $this->assertSame(2, KalendarStavka::query()->count());
    }
}
