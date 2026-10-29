<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Snimci pravih odgovora šesnaest izvora (po tri stavke, bez autora) u tests/Fixtures/rss/; internet se ne koristi.
// Paket 58: četiri nova izvora (KOMS, dva ministarstva, Infostud) imaju i svoj test, UvozNovihIzvoraTest. Paket 78: osam sajtova romskih organizacija; opisi su skraćeni na 250 znakova da u snimke ne uđu imena ljudi.
#[Group('baza')]
class UvozSnimljenihIzvoraTest extends BazaTestCase
{
    use RefreshDatabase;

    private const SNIMCI = [
        'Fond za mlade talente' => 'fond-za-mlade-talente',
        'Erasmus+ Srbija' => 'erasmus-plus-srbija',
        'EU mogućnosti - konkursi' => 'eu-mogucnosti-konkursi',
        'Youth.rs' => 'youth-rs',
        'KOMS - konkursi' => 'koms',
        'Ministarstvo prosvete' => 'ministarstvo-prosvete',
        'Ministarstvo za ljudska i manjinska prava' => 'ministarstvo-ljudska-prava',
        'Infostud Poslovi' => 'infostud-poslovi',
        'Forum Roma Srbije' => 'forum-roma-srbije',
        'Kancelarija za inkluziju Roma' => 'kancelarija-za-inkluziju-roma',
        'Romska ženska mreža' => 'romska-zenska-mreza',
        'Bibija' => 'bibija',
        'Rromanipen' => 'rromanipen',
        'ROSA' => 'rosa-leskovac',
        'Osvit' => 'osvit',
        'Udruženje Roma Novi Bečej' => 'udruzenje-roma-novi-becej',
    ];

    // Sve veze su https; Infostud ih u RSS-u daje kao http, pa se pri uvozu prepisuju (paket 64); svaki izvor ima svoje pravilo.
    // Bibija daje svoje stranice kao http i uvoz ih ne prepisuje (obe adrese rade bez preusmerenja, izmereno 29.10.2026.; PITANJA-78).
    private const POSEBNE_VEZE = [
        'Infostud Poslovi' => '#^https://poslovi\.infostud\.com/posao/\S+$#',
        'Bibija' => '#^http://www\.bibija\.org\.rs/vesti/\S+$#',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, 'Europe/Belgrade'));

        $odgovori = [];
        foreach (config('uvoz.izvori') as $izvor) {
            $odgovori[$izvor['adresa']] = Http::response(file_get_contents(base_path('tests/Fixtures/rss/'.self::SNIMCI[$izvor['ime']].'.xml')));
            // Druga strana je prazna: izvor koji je traži staje tu, a zahtev ostaje zabeležen (vidi samo_infostud_i_novi_becej_ne_traze_drugu_stranu).
            $odgovori[$izvor['adresa'].(str_contains($izvor['adresa'], '?') ? '&' : '?').'paged=2'] = Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>');
        }
        Http::fake($odgovori);
    }

    #[Test]
    public function svih_sesnaest_izvora_iz_podesavanja_uvozi_nacrte_iz_snimaka(): void
    {
        $this->artisan('uvoz:rss')
            ->expectsOutput('Fond za mlade talente: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Erasmus+ Srbija: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('EU mogućnosti - konkursi: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Youth.rs: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('KOMS - konkursi: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Ministarstvo prosvete: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Ministarstvo za ljudska i manjinska prava: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Infostud Poslovi: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Forum Roma Srbije: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Kancelarija za inkluziju Roma: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Romska ženska mreža: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Bibija: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Rromanipen: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('ROSA: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Osvit: novih 3, preskočeno 0, greška: nema')
            ->expectsOutput('Udruženje Roma Novi Bečej: novih 3, preskočeno 0, greška: nema')
            ->assertExitCode(0);

        $this->assertSame(48, Prilika::query()->count());
    }

    #[Test]
    public function svaki_uvezeni_nacrt_je_ispravan_i_bez_html_a(): void
    {
        $this->artisan('uvoz:rss');

        $imena = array_keys(self::SNIMCI);

        foreach (Prilika::query()->get() as $prilika) {
            $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
            $this->assertSame(VrstaPrilike::Drugo, $prilika->vrsta);
            $this->assertContains($prilika->naziv_izvora, $imena);
            $this->assertMatchesRegularExpression(self::POSEBNE_VEZE[$prilika->naziv_izvora] ?? '#^https://\S+$#', $prilika->link_izvora);
            $this->assertNotSame('', $prilika->naslov);
            $this->assertNotSame('', $prilika->kratak_opis);
            $this->assertLessThanOrEqual(300, mb_strlen($prilika->kratak_opis));
            $this->assertSame(0, preg_match('/[<>]/', $prilika->naslov.$prilika->kratak_opis), 'HTML u: '.$prilika->naslov);
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $prilika->naslov.$prilika->kratak_opis), 'Ćirilica u: '.$prilika->naslov);
            $this->assertNull($prilika->rok);
            $this->assertSame('2026-10-05 12:00:00', $prilika->created_at->toDateTimeString());
        }

        $this->assertSame(0, Prilika::javne()->count());
    }

    // Ogledalo: drugo pokretanje nad istim snimcima ne pravi nijedan nacrt.
    #[Test]
    public function drugo_pokretanje_nad_istim_snimcima_ne_pravi_nista_novo(): void
    {
        $this->artisan('uvoz:rss');

        $this->artisan('uvoz:rss')
            ->expectsOutput('Fond za mlade talente: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('Youth.rs: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('Ministarstvo za ljudska i manjinska prava: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('Infostud Poslovi: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('Forum Roma Srbije: novih 0, preskočeno 3, greška: nema')
            ->expectsOutput('Udruženje Roma Novi Bečej: novih 0, preskočeno 3, greška: nema');

        $this->assertSame(48, Prilika::query()->count());
    }

    #[Test]
    public function svaki_izvor_iz_podesavanja_ima_svoj_snimak(): void
    {
        $this->assertSame(array_keys(self::SNIMCI), array_column(config('uvoz.izvori'), 'ime'));

        foreach (self::SNIMCI as $snimak) {
            $this->assertFileExists(base_path('tests/Fixtures/rss/'.$snimak.'.xml'));
        }
    }

    // Ogledalo: snimak Fonda je ćirilicom (neprazan skup za merilo), a nacrti iz njega su latinicom.
    #[Test]
    public function fond_pise_cirilicom_a_nacrti_iz_njega_latinicom(): void
    {
        $snimak = (string) file_get_contents(base_path('tests/Fixtures/rss/fond-za-mlade-talente.xml'));

        $this->assertSame(1, preg_match('/\p{Cyrillic}/u', $snimak));

        $this->artisan('uvoz:rss');

        $naslovi = Prilika::query()->where('naziv_izvora', 'Fond za mlade talente')->orderBy('id')->pluck('naslov')->all();

        $this->assertCount(3, $naslovi);
        $this->assertSame('Lista preliminarnih rezultata za nagrađivanje učenika srednjih škola', $naslovi[0]);
    }

    // Ogledalo: Kancelarija za inkluziju Roma piše ćirilicom (svih tri naslova u snimku), a nacrti iz nje su latinicom (paket 78).
    #[Test]
    public function kancelarija_pise_cirilicom_a_nacrti_iz_nje_latinicom(): void
    {
        $snimak = (string) file_get_contents(base_path('tests/Fixtures/rss/kancelarija-za-inkluziju-roma.xml'));

        $this->assertSame(3, preg_match_all('/<title>[^<]*\p{Cyrillic}[^<]*<\/title>/u', $snimak));

        $this->artisan('uvoz:rss');

        $naslovi = Prilika::query()->where('naziv_izvora', 'Kancelarija za inkluziju Roma')->orderBy('id')->pluck('naslov')->all();

        $this->assertCount(3, $naslovi);
        $this->assertSame('Romski kulturni centar organizovao šestu manifestaciju posvećenu romskoj kulturi i muzici u Deronjama', $naslovi[0]);
        $this->assertSame(0, Prilika::query()->where('naziv_izvora', 'Kancelarija za inkluziju Roma')->get()->filter(fn (Prilika $p) => preg_match('/\p{Cyrillic}/u', $p->naslov.$p->kratak_opis) === 1)->count());
    }

    // Paket 78: Joomla izvor (Novi Bečej) ne zna „paged" i vraća istu stranu, pa se, kao Infostud, čita samo prva; ostalih četrnaest traže i drugu.
    #[Test]
    public function samo_infostud_i_novi_becej_ne_traze_drugu_stranu(): void
    {
        $this->artisan('uvoz:rss');

        $saPaged = [];
        $bezPaged = [];
        foreach (Http::recorded() as [$zahtev]) {
            $ime = collect(config('uvoz.izvori'))->first(fn (array $izvor) => parse_url($izvor['adresa'], PHP_URL_HOST) === parse_url($zahtev->url(), PHP_URL_HOST))['ime'];
            str_contains($zahtev->url(), 'paged=2') ? $saPaged[] = $ime : $bezPaged[] = $ime;
        }

        $this->assertCount(14, $saPaged);
        $this->assertNotContains('Infostud Poslovi', $saPaged);
        $this->assertNotContains('Udruženje Roma Novi Bečej', $saPaged);
        $this->assertCount(16, $bezPaged);
    }
}
