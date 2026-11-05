<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\RokSaInfostuda;
use App\Services\TekstStrane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 76 (Ivan, PITANJA-76, izbor A): model i provera citata čitaju samo <article> kad ga strana ima tačno jedan, pa meni sajta nije dokaz grupe; Infostud ostaje sa celom stranom,
// jer oglasi na engleskom nemaju „posao" u <article> i vrsta im se dokazuje okvirom sajta (izmereno: bez tog izuzetka otpadaju četiri od dvadeset tačnih objava). Snimci su prave strane.
#[Group('baza')]
class ClanakJeTekstPozivaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const CLAUDE = LazniClaude::ADRESA;

    private const YOUTH = 'https://youth.rs/archives/18211';

    private const INFOSTUD = 'https://poslovi.infostud.com/posao/probna-firma/probna-firma-doo/764795';

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

    private function preuzmi(string $adresa, string $html): string
    {
        Http::fake([$adresa => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        return app(TekstStrane::class)->preuzmi($adresa, 12000)->tekst;
    }

    /** @param  array<string, mixed>  $odgovor */
    private function obradi(string $izvor, string $naslov, string $adresa, string $html, array $odgovor, ?string $izvorObjavljen = null): Prilika
    {
        config()->set('uvoz.izvori', [['ime' => $izvor, 'adresa' => 'https://a.test/feed']]);
        $nacrt = Prilika::factory()->create(['naslov' => $naslov, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => $izvor, 'link_izvora' => $adresa, 'izvor_objavljen' => $izvorObjavljen]);
        Http::fake([$adresa => Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']), self::CLAUDE => LazniClaude::odgovor(json_encode($odgovor, JSON_UNESCAPED_UNICODE))]);

        $this->artisan('uvoz:obradi')->assertExitCode(0);

        return $nacrt->fresh();
    }

    // Youth.rs ima jedan <article> sa pozivom, a meni „[ savetovalište za mlade ]" je van njega: tekst koji ide modelu je poziv, bez menija.
    #[Test]
    public function tekst_youth_rs_strane_je_poziv_bez_menija(): void
    {
        $html = $this->snimak('youth-atlas-neizgovorenih-emocija.html');
        $this->clanak($html);

        $tekst = $this->preuzmi(self::YOUTH, $html);

        $this->assertStringContainsString('Pozivamo mlade (18 do 25 godina)', $tekst);
        $this->assertStringContainsString('⏰ Prijave do 30. jula', $tekst);
        $this->assertStringNotContainsString('savetovalište za mlade', mb_strtolower($tekst));
        $this->assertStringNotContainsString('Sign in', $tekst);
        $this->assertSame(TekstStrane::izHtmla($this->clanak($html)), $tekst);

        // Ogledalo na istom snimku: cela strana (izHtmla bez zahteva) ima i meni, i duža je.
        $cela = TekstStrane::izHtmla($html);
        $this->assertStringContainsString('savetovalište za mlade', mb_strtolower($cela));
        $this->assertGreaterThan(mb_strlen($tekst), mb_strlen($cela));
    }

    // Stavka iz paketa 74: za grupu „mladi" kod je kao dokaz upisivao red menija. Sada je dokaz rečenica iz poziva, doslovno iz teksta poziva.
    #[Test]
    public function dokaz_grupe_na_youth_rs_je_recenica_iz_poziva_a_ne_red_menija(): void
    {
        $odgovor = json_decode($this->snimak('youth-atlas-neizgovorenih-emocija.odgovor-modela.json'), true, flags: JSON_THROW_ON_ERROR);

        // Strana piše „Prijave do 30. jula" bez godine, pa godinu daje dan objave iz izvora (RSS): 20.7.2026.
        $prilika = $this->obradi('Youth.rs', 'JAVNI POZIV: Atlas neizgovorenih emocija', self::YOUTH, $this->snimak('youth-atlas-neizgovorenih-emocija.html'), $odgovor, '2026-07-20');

        $citat = (string) $prilika->predlog['grupe'][0]['citat'];

        $this->assertSame(['mladi'], $prilika->predlog['prihvacene_grupe']);
        $this->assertStringNotContainsStringIgnoringCase('savetovalište', $citat);
        $this->assertStringNotContainsString('Sign in', $citat);
        $this->assertStringContainsString(TekstStrane::normalizuj($citat), TekstStrane::normalizuj(TekstStrane::izHtmla($this->clanak($this->snimak('youth-atlas-neizgovorenih-emocija.html')))));
        $this->assertStringContainsStringIgnoringCase('mladim ljudima', $citat);
    }

    // Zašto Infostud ostaje sa celom stranom: oglas na engleskom nema ni „posao" ni „radno mesto" u <article>; vrsta „posao" mu se dokazuje rečima iz napomene sajta ispod oglasa („zapošljavanju", „zaposlenog").
    #[Test]
    public function infostud_oglas_na_engleskom_dokazuje_posao_samo_okvirom_sajta_van_article(): void
    {
        $html = $this->snimak('infostud-oglas-engleski-jedan-article.html');

        $this->assertTrue(VrstaPrilike::Posao->potkrepljenaTekstom(TekstStrane::izHtmla($html)));
        $this->assertFalse(VrstaPrilike::Posao->potkrepljenaTekstom(TekstStrane::izHtmla($this->clanak($html))));
    }

    // Isti snimak, dve adrese: sa Infostuda se čita cela strana (sa napomenom ispod oglasa), sa bilo koje druge samo <article>.
    #[Test]
    public function infostud_se_cita_ceo_a_isti_html_sa_druge_adrese_samo_article(): void
    {
        $html = $this->snimak('infostud-oglas-engleski-jedan-article.html');

        $sInfostuda = $this->preuzmi(self::INFOSTUD, $html);
        $saDruge = $this->preuzmi('https://neki-sajt.test/oglas/1', $html);

        $this->assertSame(TekstStrane::izHtmla($html), $sInfostuda);
        $this->assertSame(TekstStrane::izHtmla($this->clanak($html)), $saDruge);
        // Reči koje dokazuju „posao" stoje u napomeni ispod oglasa („… ne vrši posredovanje u zapošljavanju …"), van <article>.
        $this->assertStringContainsString('posredovanje u zapošljavanju', $sInfostuda);
        $this->assertStringNotContainsString('posredovanje u zapošljavanju', $saDruge);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function adrese(): array
    {
        return [
            'oglas' => ['https://poslovi.infostud.com/posao/x/y/1', true],
            'oglas sa upitom' => ['https://poslovi.infostud.com/posao/x/y/1?utm_source=rss', true],
            'http' => ['http://poslovi.infostud.com/posao/x/y/1', true],
            'drugi domen sa istim početkom' => ['https://poslovi.infostud.com.lazni.rs/posao/1', false],
            'drugi poddomen' => ['https://www.infostud.com/posao/1', false],
            'sajt koji ga pominje' => ['https://youth.rs/archives/1?r=poslovi.infostud.com', false],
        ];
    }

    #[Test]
    #[DataProvider('adrese')]
    public function samo_adresa_sa_servera_infostuda_je_infostud(string $adresa, bool $jeste): void
    {
        $this->assertSame($jeste, RokSaInfostuda::jeInfostud($adresa));
    }

    // Šta svaki izbor mora da sačuva: oglas se objavljuje, a rok je iz polja isteka, jer isti datum piše u <article> („13.11.2026.").
    #[Test]
    public function infostud_oglas_na_engleskom_se_objavljuje_sa_rokom_iz_polja_isteka(): void
    {
        $html = $this->snimak('infostud-oglas-engleski-jedan-article.html');
        $this->assertStringContainsString('"expirationDate":"13.11.2026."', $html);
        $this->assertStringContainsString('13.11.2026.', TekstStrane::izHtmla($this->clanak($html)));

        $prilika = $this->obradi('Infostud Poslovi', 'Maintenance Coordinator / USA Market', self::INFOSTUD, $html, [
            'vazi_pravilo' => true, 'razlog' => 'Oglas za posao.', 'vrsta' => 'posao', 'rok' => null, 'citat' => null, 'grupe' => [],
        ]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-11-13', $prilika->rok->toDateString());
        $this->assertTrue($prilika->predlog['rok_sa_infostuda']);
    }
}
