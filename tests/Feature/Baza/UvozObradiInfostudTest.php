<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\ObradaNacrta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 63/T4: oglas sa poslovi.infostud.com nema reč „rok"; kad model ne nađe rok, kod uzima expirationDate iz strane, ali samo ako isti datum piše i u tekstu strane.
#[Group('baza')]
class UvozObradiInfostudTest extends BazaTestCase
{
    use RefreshDatabase;

    private const CLAUDE = LazniClaude::ADRESA;

    private const OGLAS = 'https://poslovi.infostud.com/posao/magacioner/probna-firma-doo/760840';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Infostud Poslovi', 'adresa' => 'https://rss.infostud.com/poslovi/rss.php']]);
    }

    private function strana(string $izmena = ''): string
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/stranice/infostud-oglas.html'));

        if ($izmena === '') {
            return $html;
        }

        [$staro, $novo] = explode('=>', $izmena, 2);
        $this->assertStringContainsString($staro, $html, 'izmena strane ne pogađa fixture');

        return str_replace($staro, $novo, $html);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function claude(array $dopuna = []): mixed
    {
        $forma = [...['vazi_pravilo' => true, 'razlog' => 'Oglas za posao.', 'vrsta' => 'posao', 'rok' => null, 'citat' => null, 'grupe' => []], ...$dopuna];

        return LazniClaude::odgovor(json_encode($forma, JSON_UNESCAPED_UNICODE));
    }

    private function nacrt(string $adresa = self::OGLAS): Prilika
    {
        return Prilika::factory()->create(['naslov' => 'Magacioner', 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Infostud Poslovi', 'link_izvora' => $adresa]);
    }

    /** @param  array<string, mixed>  $model */
    private function obradi(Prilika $nacrt, string $html, array $model = []): Prilika
    {
        Http::fake([(string) $nacrt->link_izvora => Http::response($html), self::CLAUDE => $this->claude($model)]);

        $this->artisan('uvoz:obradi');

        return $nacrt->fresh();
    }

    /** @return array<string, array{0: bool}> */
    public static function misljenja(): array
    {
        return ['model: prilika' => [true], 'model: nije prilika' => [false]];
    }

    #[Test]
    #[DataProvider('misljenja')]
    public function kad_model_ne_nadje_rok_uzima_se_datum_isteka_iz_strane_koji_piše_i_u_tekstu(bool $vazi): void
    {
        $prilika = $this->obradi($this->nacrt(), $this->strana(), ['vazi_pravilo' => $vazi]);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame(VrstaPrilike::Posao, $prilika->vrsta);
        $this->assertSame('2026-10-25', $prilika->rok->toDateString());
        $this->assertSame('25.10.2026.', $prilika->predlog['citat']);
        $this->assertNull($prilika->predlog['rok_modela']);
        $this->assertTrue($prilika->predlog['rok_sa_infostuda']);
        $this->assertNull($prilika->predlog['odbijeno']);
        $this->assertSame(ObradaNacrta::PRAVILO, $prilika->predlog['pravilo']);
    }

    // Ogledalo: ista strana sa drugog sajta nema ovo pravilo, pa bez roka modela ostaje nacrt.
    #[Test]
    public function ista_strana_sa_drugog_sajta_ne_daje_rok(): void
    {
        foreach (['https://a.test/oglas', 'https://poslovi.infostud.com.lazni.test/oglas', 'https://lazni-poslovi.infostud.com.test/oglas', 'https://infostud.com/oglas'] as $adresa) {
            Prilika::query()->delete();
            $prilika = $this->obradi($this->nacrt($adresa), $this->strana());

            $this->assertSame(StatusPrilike::Nacrt, $prilika->status, $adresa);
            $this->assertSame('nema ispravnog roka', $prilika->predlog['odbijeno'], $adresa);
            $this->assertFalse($prilika->predlog['rok_sa_infostuda'], $adresa);
        }
    }

    // Adresa iz RSS-a je „http", a server je preusmerava na „https": važi i jedno i drugo.
    #[Test]
    public function http_i_https_adresa_sa_infostuda_daju_isti_rok(): void
    {
        $prilika = $this->obradi($this->nacrt('http://poslovi.infostud.com/posao/magacioner/probna-firma-doo/760840'), $this->strana());

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-10-25', $prilika->rok->toDateString());
    }

    /** @return array<string, array{0: string, 1: string}> izmena strane („staro=>novo") i razlog zbog kog oglas ostaje nacrt */
    public static function straneBezDokaza(): array
    {
        return [
            'datum nije u tekstu strane' => ['<span>25.10.2026.</span>=><span></span>', 'nema ispravnog roka'],
            'u tekstu je deo drugog broja' => ['<span>25.10.2026.</span>=><span>125.10.2026.</span>', 'nema ispravnog roka'],
            'u tekstu je drugi datum' => ['<span>25.10.2026.</span>=><span>26.10.2026.</span>', 'nema ispravnog roka'],
            'dva različita datuma u strani' => ['"expirationDate":"25.10.2026.","location"=>"expirationDate":"25.10.2026.","expirationDate":"26.10.2026.","location"', 'nema ispravnog roka'],
            'polje nije u obliku d.m.Y.' => ['"expirationDate":"25.10.2026."=>"expirationDate":"2026-10-25"', 'nema ispravnog roka'],
            'datum koji ne postoji' => ['25.10.2026.=>31.02.2026.', 'nema ispravnog roka'],
            'strana nema polje' => ['"expirationDate":"25.10.2026.",=>', 'nema ispravnog roka'],
            'rok je prošao' => ['25.10.2026.=>01.10.2026.', 'rok je prošao'],
        ];
    }

    #[Test]
    #[DataProvider('straneBezDokaza')]
    public function datum_koji_nije_dokazan_na_strani_ne_daje_rok(string $izmena, string $razlog): void
    {
        $prilika = $this->obradi($this->nacrt(), $this->strana($izmena));

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame($razlog, $prilika->predlog['odbijeno']);
        $this->assertNull($prilika->rok);
    }

    // Model koji je našao rok ostaje merodavan: dokazan rok modela (iz teksta oglasa) važi i kad je datum isteka drugi.
    #[Test]
    public function dokazan_rok_koji_je_model_nasao_se_ne_menja_datumom_isteka(): void
    {
        $sa = $this->strana('<p>Traži se magacioner=><p>Rok za prijavu je 22. oktobra 2026. godine. Traži se magacioner');
        $prilika = $this->obradi($this->nacrt(), $sa, ['rok' => '2026-10-22', 'citat' => 'Rok za prijavu je 22. oktobra 2026. godine']);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-10-22', $prilika->rok->toDateString());
        $this->assertSame('2026-10-22', $prilika->predlog['rok_modela']);
        $this->assertFalse($prilika->predlog['rok_sa_infostuda']);
    }

    /** @return array<string, array{0: array<string, mixed>}> rok koji model vraća, a nije dokazan citatom sa strane */
    public static function nedokazaniRokoviModela(): array
    {
        return [
            'citat koga nema na strani' => [['rok' => '2026-10-30', 'citat' => 'Rok je 30. oktobra, ne piše nigde']],
            'datum koji ne postoji' => [['rok' => '2026-13-45', 'citat' => 'Rok za prijavu je 45. 13. 2026.']],
            'rok sa citatom koji ne sadrži datum' => [['rok' => '2026-10-30', 'citat' => 'Traži se magacioner']],
            'prazan citat' => [['rok' => '2026-10-30', 'citat' => '']],
            'rok bez citata' => [['rok' => '2026-10-30', 'citat' => null]],
        ];
    }

    // Paket 67 (PITANJA-63.md 4, Ivan „da" 11.10.2026.): rok koji je model vratio, a nije dokazan citatom sa strane, ne drži oglas u nacrtu: uzima se datum isteka iz
    // strane, uz isti uslov kao pre (isti datum piše i u tekstu). Šta je model rekao ostaje u predlogu (rok_modela).
    /** @param  array<string, mixed>  $model */
    #[Test]
    #[DataProvider('nedokazaniRokoviModela')]
    public function nedokazan_rok_modela_se_zamenjuje_datumom_isteka_koji_piše_i_u_tekstu(array $model): void
    {
        $prilika = $this->obradi($this->nacrt(), $this->strana(), $model);

        $this->assertSame(StatusPrilike::Objavljeno, $prilika->status);
        $this->assertSame('2026-10-25', $prilika->rok->toDateString());
        $this->assertSame('25.10.2026.', $prilika->predlog['citat']);
        $this->assertSame($model['rok'], $prilika->predlog['rok_modela']);
        $this->assertTrue($prilika->predlog['rok_sa_infostuda']);
        $this->assertNull($prilika->predlog['odbijeno']);
    }

    // Ogledalo: datum isteka koji ne piše u tekstu strane ne pomaže ni kad je rok modela nedokazan: nacrt, kao pre, a rok modela ostaje zapisan.
    #[Test]
    public function datum_isteka_koji_ne_piše_u_tekstu_ne_pomaže_nedokazanom_roku_modela(): void
    {
        $prilika = $this->obradi($this->nacrt(), $this->strana('<span>25.10.2026.</span>=><span></span>'), ['rok' => '2026-10-30', 'citat' => 'Rok je 30. oktobra, ne piše nigde']);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno']);
        $this->assertSame('2026-10-30', $prilika->predlog['rok_modela']);
        $this->assertFalse($prilika->predlog['rok_sa_infostuda']);
        $this->assertNull($prilika->rok);
    }

    // Dokazan rok modela se ne menja, ni kad je prošao: datum isteka ga ne „spasava".
    #[Test]
    public function dokazan_rok_modela_koji_je_prosao_ostaje_nacrt_a_datum_isteka_ga_ne_menja(): void
    {
        $sa = $this->strana('<p>Traži se magacioner=><p>Rok za prijavu je bio 1. oktobra 2026. godine. Traži se magacioner');
        $prilika = $this->obradi($this->nacrt(), $sa, ['rok' => '2026-10-01', 'citat' => 'Rok za prijavu je bio 1. oktobra 2026. godine']);

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('rok je prošao', $prilika->predlog['odbijeno']);
        $this->assertSame('2026-10-01', $prilika->predlog['rok']);
        $this->assertFalse($prilika->predlog['rok_sa_infostuda']);
    }

    // Sa drugog sajta ne važi ni novo pravilo: nedokazan rok modela tamo ostaje nacrt.
    #[Test]
    public function nedokazan_rok_modela_sa_drugog_sajta_ostaje_nacrt(): void
    {
        foreach (['https://a.test/oglas', 'https://poslovi.infostud.com.lazni.test/oglas'] as $adresa) {
            Prilika::query()->delete();
            $prilika = $this->obradi($this->nacrt($adresa), $this->strana(), ['rok' => '2026-10-30', 'citat' => 'Rok je 30. oktobra, ne piše nigde']);

            $this->assertSame(StatusPrilike::Nacrt, $prilika->status, $adresa);
            $this->assertSame('rok ne piše u tekstu strane', $prilika->predlog['odbijeno'], $adresa);
            $this->assertFalse($prilika->predlog['rok_sa_infostuda'], $adresa);
        }
    }

    // Ostalo ostaje isto i sa rokom iz strane: vrsta se dokazuje rečju sa strane.
    #[Test]
    public function vrsta_se_i_dalje_dokazuje_rečju_sa_strane(): void
    {
        $prilika = $this->obradi($this->nacrt(), str_replace(['Poslovi', 'zapošljavanju', 'posredovanje'], ['Vesti', 'radu', 'veze'], $this->strana()));

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('vrsta nije potkrepljena', $prilika->predlog['odbijeno']);
    }

    // I naslov rezultata ili spiska nije oglas, sa rokom iz strane.
    #[Test]
    public function naslov_rezultata_ili_spiska_i_dalje_nije_oglas(): void
    {
        $nacrt = $this->nacrt();
        $nacrt->forceFill(['naslov' => 'Lista kandidata'])->save();

        $prilika = $this->obradi($nacrt, $this->strana());

        $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
        $this->assertSame('naslov je rezultat ili spisak', $prilika->predlog['odbijeno']);
    }
}
