<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Models\Organizacija;
use App\Support\EpostaAdresa;
use App\Support\LinkSajta;
use Database\Seeders\RomskeOrganizacijeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 75/T1 i 77/T1 (Ivan, 13.10. i 29.10.2026.): četrnaest romskih organizacija iz Dokumentacija/ORGANIZACIJE-NOVE.md (šest iz prve i osam iz druge runde), samo ✅, doslovno;
// seeder dodaje samo slug koji još ne postoji.
#[Group('baza')]
class RomskeOrganizacijeSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    private const SLUGOVI = [
        'opre-roma-srbija', 'eriac', 'roma-education-fund', 'redi', 'roma-foundation-for-europe', 'roma-for-democracy',
        'kancelarija-za-inkluziju-roma', 'osvit', 'bibija', 'romska-zenska-mreza-srbije', 'udruzenje-roma-novi-becej', 'rromanipen', 'rosa-leskovac', 'forum-roma-srbije',
    ];

    /** @var array<string, string> slug → broj iza „tel:" (prvi broj iz polja) za organizacije koje imaju telefon */
    private const TELEFONI = [
        'eriac' => '0116906139', 'kancelarija-za-inkluziju-roma' => '0214881723', 'osvit' => '0800100909', 'romska-zenska-mreza-srbije' => '0653234950',
        'udruzenje-roma-novi-becej' => '023774959', 'rromanipen' => '034311869',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 29, 12, 0, 0, 'Europe/Belgrade'));
    }

    #[Test]
    public function seeder_pravi_cetrnaest_organizacija_i_sve_su_objavljene_i_vide_se_na_strani(): void
    {
        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->assertSame(14, Organizacija::query()->count());
        $this->assertSame(self::SLUGOVI, array_column(RomskeOrganizacijeSeeder::organizacije(), 'slug'));
        $this->assertSame(14, Organizacija::query()->where('status', StatusObjave::Objavljeno)->count());
        $this->assertSame(14, Organizacija::javne()->count());

        $strana = $this->get(route('organizacije.index'))->assertOk();

        foreach (RomskeOrganizacijeSeeder::organizacije() as $organizacija) {
            $strana->assertSee($organizacija['naziv']);
            $this->get(route('organizacije.show', $organizacija['slug']))->assertOk()->assertSee($organizacija['naziv'])->assertSee($organizacija['kratak_opis']);
        }
    }

    // Svaka organizacija ima sve što pravilo objave i strana traže: ispravan sajt i e-poštu, usluge i belešku.
    #[Test]
    public function svaka_organizacija_ima_ispravne_podatke(): void
    {
        $this->assertCount(14, RomskeOrganizacijeSeeder::organizacije());

        foreach (RomskeOrganizacijeSeeder::organizacije() as $organizacija) {
            $this->assertLessThanOrEqual(64, mb_strlen($organizacija['telefon'] ?? ''), $organizacija['slug']);
            $this->assertTrue(LinkSajta::jeIspravan($organizacija['sajt']), $organizacija['slug']);
            $this->assertTrue(EpostaAdresa::jeIspravna($organizacija['eposta']), $organizacija['slug']);
            $this->assertNotEmpty($organizacija['usluge'], $organizacija['slug']);
            $this->assertNotSame('', trim($organizacija['beleska']), $organizacija['slug']);
            $this->assertTrue($organizacija['online'], $organizacija['slug']);
        }
    }

    // Bez telefona nema dugmeta „Pozovi"; telefon imaju ERIAC, Kancelarija, Osvit, Romska ženska mreža, Novi Bečej i Rromanipen. Dugme uzima prvi broj iz polja (kod Osvita je to SOS).
    #[Test]
    public function telefon_i_dugme_za_poziv_imaju_samo_organizacije_sa_telefonom_a_dugme_uzima_prvi_broj(): void
    {
        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->assertEqualsCanonicalizing(array_keys(self::TELEFONI), Organizacija::query()->whereNotNull('telefon')->pluck('slug')->all());
        $this->assertSame('011 6906139', Organizacija::query()->where('slug', 'eriac')->sole()->telefon);
        $this->assertStringStartsWith('0800/100909 (SOS', (string) Organizacija::query()->where('slug', 'osvit')->sole()->telefon);

        $strana = $this->get(route('organizacije.index'))->assertOk()->getContent();
        $this->assertSame(count(self::TELEFONI), substr_count($strana, 'href="tel:'));

        foreach (self::TELEFONI as $slug => $broj) {
            $this->assertStringContainsString('href="tel:'.$broj.'"', $strana, $slug);
        }

        foreach (array_diff(self::SLUGOVI, array_keys(self::TELEFONI)) as $slug) {
            $this->get(route('organizacije.show', $slug))->assertOk()->assertDontSee('href="tel:', false);
        }
    }

    // Drugo pokretanje ne menja ništa: ni red, ni vreme izmene.
    #[Test]
    public function drugo_pokretanje_ne_menja_nista(): void
    {
        $this->seed(RomskeOrganizacijeSeeder::class);
        $pre = Organizacija::query()->orderBy('id')->get()->map(fn (Organizacija $organizacija) => $organizacija->getAttributes())->all();

        $this->travel(3)->hours();
        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->assertCount(14, $pre);
        $this->assertSame($pre, Organizacija::query()->orderBy('id')->get()->map(fn (Organizacija $organizacija) => $organizacija->getAttributes())->all());
    }

    // Ogledalo: organizacija čiji slug već postoji (ovde Ivanova verzija ERIAC-a kao nacrt) ostaje kakva je, a dodaju se samo ostale (13).
    #[Test]
    public function slug_koji_vec_postoji_se_ne_dira_a_ostali_se_dodaju(): void
    {
        Organizacija::query()->create(['naziv' => 'ERIAC (moja verzija)', 'slug' => 'eriac', 'vrsta' => VrstaOrganizacije::Drugo, 'kratak_opis' => 'Moj opis.', 'opis' => 'Ivanov tekst.', 'status' => StatusObjave::Nacrt]);
        $pre = Organizacija::query()->where('slug', 'eriac')->sole()->getAttributes();

        $this->seed(RomskeOrganizacijeSeeder::class);

        $this->assertSame(14, Organizacija::query()->count());
        $this->assertSame($pre, Organizacija::query()->where('slug', 'eriac')->sole()->getAttributes());
        $this->assertSame(13, Organizacija::javne()->count());
        $this->assertSame(StatusObjave::Nacrt, Organizacija::query()->where('slug', 'eriac')->sole()->status);
    }

    // Tekstovi su isti kao u dokumentu, samo ✅. Dokumentacija je van gita, pa se bez nje test glasno preskače.
    #[Test]
    public function tekstovi_su_doslovno_isti_kao_u_dokumentu_i_samo_odobreni(): void
    {
        $putanja = base_path('Dokumentacija/ORGANIZACIJE-NOVE.md');

        if (! is_file($putanja)) {
            $this->markTestSkipped('Dokumentacija/ORGANIZACIJE-NOVE.md nema na ovom računaru (folder Dokumentacija je van gita), pa se tekstovi ne mogu uporediti.');
        }

        $odeljci = preg_split('/^## /m', (string) file_get_contents($putanja)) ?: [];
        $poredjeno = 0;

        foreach (RomskeOrganizacijeSeeder::organizacije() as $organizacija) {
            $odeljak = collect($odeljci)->first(fn (string $deo) => str_contains((string) strtok($deo, "\n"), '`'.$organizacija['slug'].'`'));
            $this->assertNotNull($odeljak, $organizacija['slug']);
            $this->assertStringContainsString('✅', (string) strtok($odeljak, "\n"), $organizacija['slug'].' nije odobren u dokumentu');
            $this->assertStringStartsWith($organizacija['naziv'].' · `'.$organizacija['slug'].'`', $odeljak);

            preg_match_all('/^- ([^:]+): (.*)$/m', $odeljak, $nalazi, PREG_SET_ORDER);
            $polja = array_column($nalazi, 2, 1);

            $this->assertSame($polja['Vrsta'], $organizacija['vrsta']->getLabel(), $organizacija['slug']);
            $this->assertSame($polja['Ukratko'], $organizacija['kratak_opis'], $organizacija['slug']);
            $this->assertSame($polja['Opis'], $organizacija['opis'], $organizacija['slug']);
            $this->assertSame($polja['Mesto'], $organizacija['mesto'], $organizacija['slug']);
            $this->assertSame($polja['Online'] === 'da', $organizacija['online'], $organizacija['slug']);
            // „(nema)" znači prazno polje: ključ ne postoji, pa je telefon u bazi null.
            $this->assertSame($polja['Telefon'] === '(nema)' ? null : $polja['Telefon'], $organizacija['telefon'] ?? null, $organizacija['slug']);
            $this->assertSame($polja['E-pošta'], $organizacija['eposta'], $organizacija['slug']);
            $this->assertSame($polja['Sajt'], $organizacija['sajt'], $organizacija['slug']);
            $this->assertSame(explode('; ', $polja['Usluge']), $organizacija['usluge'], $organizacija['slug']);
            $this->assertSame($polja['Beleška'], $organizacija['beleska'], $organizacija['slug']);
            $poredjeno++;
        }

        $this->assertSame(14, $poredjeno);

        // Obrnuto: svaka organizacija sa ✅ u dokumentu je u seederu, i ništa više („Čeka proveru" i „Preskočeno" nemaju ✅ u zaglavlju).
        $uDokumentu = [];
        foreach ($odeljci as $deo) {
            if (preg_match('/^[^\n]*· `([a-z0-9-]+)` — ✅/', $deo, $zaglavlje) === 1) {
                $uDokumentu[] = $zaglavlje[1];
            }
        }
        $this->assertEqualsCanonicalizing(self::SLUGOVI, $uDokumentu);
    }
}
