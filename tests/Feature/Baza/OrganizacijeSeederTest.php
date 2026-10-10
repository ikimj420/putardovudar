<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Organizacija;
use App\Support\LinkSajta;
use Database\Seeders\OrganizacijeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class OrganizacijeSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeder_pravi_6_organizacija_sve_su_nacrt_i_nijedna_nije_javna(): void
    {
        $this->seed(OrganizacijeSeeder::class);

        $this->assertSame(6, Organizacija::query()->count());
        $this->assertSame(6, Organizacija::query()->where('status', StatusObjave::Nacrt)->count());
        $this->assertSame(0, Organizacija::javne()->count());
        $this->get(route('organizacije.index'))->assertOk()->assertDontSee('Nacionalna služba za zapošljavanje');
    }

    #[Test]
    public function svaka_organizacija_ima_podatke_i_ispravan_sajt_pa_je_ivan_moze_objaviti(): void
    {
        $this->seed(OrganizacijeSeeder::class);

        $this->assertNotSame([], OrganizacijeSeeder::organizacije());

        foreach (Organizacija::query()->get() as $organizacija) {
            $this->assertNotSame('', trim($organizacija->naziv), $organizacija->slug);
            $this->assertNotSame('', trim($organizacija->kratak_opis), $organizacija->slug);
            $this->assertNotSame('', trim((string) $organizacija->opis), $organizacija->slug);
            $this->assertNotEmpty($organizacija->usluge, $organizacija->slug);
            $this->assertNotSame('', trim((string) $organizacija->beleska), $organizacija->slug);
            $this->assertTrue(LinkSajta::jeIspravan($organizacija->sajt), $organizacija->slug);
        }

        // Dokaz da podaci zadovoljavaju pravilo objave: sve se objavljuju bez izuzetka.
        Organizacija::query()->get()->each->update(['status' => StatusObjave::Objavljeno]);

        $this->assertSame(6, Organizacija::javne()->count());
    }

    #[Test]
    public function seeder_pokrenut_dvaput_ne_pravi_duplikate(): void
    {
        $this->seed(OrganizacijeSeeder::class);
        $this->seed(OrganizacijeSeeder::class);

        $this->assertSame(6, Organizacija::query()->count());
        $this->assertSame(6, count(array_unique(array_column(OrganizacijeSeeder::organizacije(), 'slug'))));
    }

    // Ogledalo: organizacija koju je Ivan izmenio i objavio ostaje kakva je, a obrisana se vraća.
    #[Test]
    public function seeder_ne_gazi_izmenjenu_organizaciju_a_dodaje_samo_slug_koji_fali(): void
    {
        $this->seed(OrganizacijeSeeder::class);
        Organizacija::query()->where('slug', 'fondacija-tempus')->first()?->update(['opis' => 'Ivanova verzija.', 'status' => StatusObjave::Objavljeno]);
        Organizacija::query()->where('slug', 'inicijativa-a-11')->delete();

        $this->seed(OrganizacijeSeeder::class);

        $izmenjena = Organizacija::query()->where('slug', 'fondacija-tempus')->sole();

        $this->assertSame('Ivanova verzija.', $izmenjena->opis);
        $this->assertSame(StatusObjave::Objavljeno, $izmenjena->status);
        $this->assertSame(StatusObjave::Nacrt, Organizacija::query()->where('slug', 'inicijativa-a-11')->sole()->status);
        $this->assertSame(6, Organizacija::query()->count());
    }

    // Adrese iz starog projekta (Dokumentacija/NASLEDJE/podaci/OrganizationSeeder.php), za četiri organizacije; ostale dve ih nemaju.
    private const EPOSTE = [
        'ministarstvo-prosvete-stipendije-i-krediti' => 'ucenici@prosveta.gov.rs',
        'fondacija-tempus' => 'info@tempus.ac.rs',
        'krovna-organizacija-mladih-srbije' => 'office@koms.rs',
        'inicijativa-a-11' => 'office@a11initiative.org',
    ];

    #[Test]
    public function seeder_upisuje_eposte_za_cetiri_organizacije_a_ostale_dve_ostaju_bez(): void
    {
        $this->seed(OrganizacijeSeeder::class);

        $this->assertCount(4, self::EPOSTE);

        foreach (self::EPOSTE as $slug => $eposta) {
            $this->assertSame($eposta, Organizacija::query()->where('slug', $slug)->sole()->eposta, $slug);
        }

        $this->assertSame(2, Organizacija::query()->whereNull('eposta')->count());
        $this->assertSame([], array_diff(Organizacija::query()->whereNull('eposta')->pluck('slug')->all(), ['nacionalna-sluzba-za-zaposljavanje', 'fond-za-mlade-talente-republike-srbije']));
    }

    // Organizacija koja je već u bazi bez e-pošte dobija je; ništa drugo na njoj se ne menja.
    #[Test]
    public function seeder_popunjava_prazno_polje_eposte_a_nista_drugo_ne_dira(): void
    {
        $this->seed(OrganizacijeSeeder::class);
        $organizacija = Organizacija::query()->where('slug', 'fondacija-tempus')->sole();
        $organizacija->update(['eposta' => null, 'opis' => 'Ivanova verzija.', 'telefon' => '000', 'status' => StatusObjave::Objavljeno]);
        $pre = Organizacija::query()->whereKey($organizacija->getKey())->sole()->getAttributes();

        $this->seed(OrganizacijeSeeder::class);

        $posle = Organizacija::query()->whereKey($organizacija->getKey())->sole()->getAttributes();

        $this->assertSame('info@tempus.ac.rs', $posle['eposta']);
        $this->assertSame('Ivanova verzija.', $posle['opis']);
        $this->assertSame('000', $posle['telefon']);
        $this->assertSame(StatusObjave::Objavljeno->value, $posle['status']);
        // Jedina razlika u redu su e-pošta i vreme izmene; svako drugo polje je isto.
        $this->assertSame([], array_diff(array_keys(array_diff_assoc($posle, $pre)), ['eposta', 'updated_at']));
    }

    // Ogledalo (glavna tvrdnja paketa): izmenjena e-pošta ostaje kakva je i posle jednog i posle drugog pokretanja.
    #[Test]
    public function seeder_dvaput_pokrenut_ne_menja_izmenjenu_organizaciju(): void
    {
        $this->seed(OrganizacijeSeeder::class);
        $organizacija = Organizacija::query()->where('slug', 'fondacija-tempus')->sole();
        $organizacija->update(['eposta' => 'ivan@primer.rs', 'opis' => 'Ivanova verzija.']);
        $pre = Organizacija::query()->whereKey($organizacija->getKey())->sole()->getAttributes();

        $this->seed(OrganizacijeSeeder::class);
        $this->seed(OrganizacijeSeeder::class);

        $posle = Organizacija::query()->whereKey($organizacija->getKey())->sole()->getAttributes();

        $this->assertSame('ivan@primer.rs', $posle['eposta']);
        $this->assertSame($pre, $posle);
        $this->assertSame(6, Organizacija::query()->count());
    }

    // Obrisana organizacija se vraća sa e-poštom, kao nacrt.
    #[Test]
    public function obrisana_organizacija_se_vraca_sa_epostom(): void
    {
        $this->seed(OrganizacijeSeeder::class);
        Organizacija::query()->where('slug', 'inicijativa-a-11')->delete();

        $this->seed(OrganizacijeSeeder::class);

        $vracena = Organizacija::query()->where('slug', 'inicijativa-a-11')->sole();

        $this->assertSame('office@a11initiative.org', $vracena->eposta);
        $this->assertSame(StatusObjave::Nacrt, $vracena->status);
    }
}
