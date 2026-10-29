<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\TekstStrane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 76/T1, merenje (PITANJA-76): predlog „kad strana ima tačno jedan <article>, čita se samo on" NIJE uveden, jer bi na 80 strana otpale četiri tačne objave (Infostud).
// Ove tvrdnje čuvaju izmereno, na snimcima pravih strana (tests/Fixtures/stranice/). Prve dve dokumentuju rupu i crvene kad se zatvori; druge dve su ono što svaki izbor mora da sačuva.
#[Group('baza')]
class StranaSaJednimArticleTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 13, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function snimak(string $ime): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/stranice/'.$ime));
    }

    private function clanak(string $html): string
    {
        $this->assertSame(1, preg_match_all('#<article\b#i', $html), 'strana nema tačno jedan <article>');
        $this->assertSame(1, preg_match_all('#</article>#i', $html));
        preg_match('#<article\b[^>]*>(.*?)</article>#is', $html, $clanak);

        return $clanak[1];
    }

    /** @param  array<string, mixed>  $odgovor */
    private function obradi(string $izvor, string $naslov, string $adresa, string $html, array $odgovor, ?string $izvorObjavljen = null): Prilika
    {
        config()->set('uvoz.izvori', [['ime' => $izvor, 'adresa' => 'https://a.test/feed']]);
        $nacrt = Prilika::factory()->create(['naslov' => $naslov, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => $izvor, 'link_izvora' => $adresa, 'izvor_objavljen' => $izvorObjavljen]);
        Http::fake([$adresa => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']), self::OLLAMA => Http::response(['message' => ['content' => json_encode($odgovor, JSON_UNESCAPED_UNICODE)]])]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        return $nacrt->fresh();
    }

    // Rupa: Youth.rs ima jedan <article> sa pozivom, a meni „[ savetovalište za mlade ]" je van njega; tekst koji idu modelu i proveri citata danas je cela strana, pa meni ulazi.
    #[Test]
    public function danas_se_tekst_youth_rs_strane_cita_ceo_pa_meni_ulazi_u_tekst(): void
    {
        $html = $this->snimak('youth-atlas-neizgovorenih-emocija.html');
        $clanak = TekstStrane::izHtmla($this->clanak($html));

        $this->assertStringContainsString('Pozivamo mlade (18 do 25 godina)', $clanak);
        $this->assertStringNotContainsString('savetovalište za mlade', mb_strtolower($clanak));
        $this->assertStringContainsString('savetovalište za mlade', mb_strtolower(TekstStrane::izHtmla($html)));
    }

    // Rupa: zato kod za grupu „mladi" na toj strani danas nalazi red menija kao dokaz (izmereno u paketu 74). Crveni kad se strana čita drugačije.
    #[Test]
    public function danas_je_dokaz_grupe_na_youth_rs_red_menija_sajta(): void
    {
        $odgovor = json_decode($this->snimak('youth-atlas-neizgovorenih-emocija.odgovor-modela.json'), true, flags: JSON_THROW_ON_ERROR);

        // Strana piše „Prijave do 30. jula" bez godine, pa godinu daje dan objave iz izvora (RSS): 20.7.2026.
        $prilika = $this->obradi('Youth.rs', 'JAVNI POZIV: Atlas neizgovorenih emocija', 'https://youth.rs/archives/18211', $this->snimak('youth-atlas-neizgovorenih-emocija.html'), $odgovor, '2026-07-20');

        $this->assertSame(['mladi'], $prilika->predlog['prihvacene_grupe']);
        $this->assertStringContainsStringIgnoringCase('savetovalište za mlade', (string) $prilika->predlog['grupe'][0]['citat']);
    }

    // Zašto predlog ne prolazi: oglas na engleskom (Infostud) nema ni „posao" ni „radno mesto" u <article>; vrsta „posao" mu se danas dokazuje rečima iz okvira sajta („Poslovi", „Dok tražite posao").
    #[Test]
    public function infostud_oglas_na_engleskom_dokazuje_posao_samo_okvirom_sajta_van_article(): void
    {
        $html = $this->snimak('infostud-oglas-engleski-jedan-article.html');

        $this->assertTrue(VrstaPrilike::Posao->potkrepljenaTekstom(TekstStrane::izHtmla($html)));
        $this->assertFalse(VrstaPrilike::Posao->potkrepljenaTekstom(TekstStrane::izHtmla($this->clanak($html))));
    }

    // Šta svaki izbor mora da sačuva: oglas se objavljuje, a rok je iz polja isteka, jer isti datum piše u <article> („13.11.2026.").
    #[Test]
    public function infostud_oglas_na_engleskom_se_objavljuje_sa_rokom_iz_polja_isteka(): void
    {
        $html = $this->snimak('infostud-oglas-engleski-jedan-article.html');
        $this->assertStringContainsString('"expirationDate":"13.11.2026."', $html);
        $this->assertStringContainsString('13.11.2026.', TekstStrane::izHtmla($this->clanak($html)));

        $prilika = $this->obradi('Infostud Poslovi', 'Maintenance Coordinator / USA Market', 'https://poslovi.infostud.com/posao/probna-firma/probna-firma-doo/764795', $html, [
            'vazi_pravilo' => true, 'razlog' => 'Oglas za posao.', 'vrsta' => 'posao', 'rok' => null, 'citat' => null, 'grupe' => [],
        ]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-11-13', $prilika->rok->toDateString());
        $this->assertTrue($prilika->predlog['rok_sa_infostuda']);
    }
}
