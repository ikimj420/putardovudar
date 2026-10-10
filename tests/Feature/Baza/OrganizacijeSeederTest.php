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
}
