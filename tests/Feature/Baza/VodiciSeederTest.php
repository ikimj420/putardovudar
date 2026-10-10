<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Vodic;
use Database\Seeders\VodiciSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class VodiciSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeder_pravi_11_vodica_svi_su_nacrt_i_nijedan_nije_javan(): void
    {
        $this->seed(VodiciSeeder::class);

        $this->assertSame(11, Vodic::query()->count());
        $this->assertSame(11, Vodic::query()->where('status', StatusObjave::Nacrt)->count());
        $this->assertSame(0, Vodic::javni()->count());
        $this->get(route('vodici.index'))->assertOk()->assertDontSee('Kako napisati prvi CV');
    }

    #[Test]
    public function svaki_vodic_ima_naslov_slug_opis_tekst_korake_i_belesku(): void
    {
        $this->seed(VodiciSeeder::class);

        $this->assertNotSame([], VodiciSeeder::vodici());

        foreach (Vodic::query()->get() as $vodic) {
            $this->assertNotSame('', trim($vodic->naslov), $vodic->slug);
            $this->assertNotSame('', trim($vodic->kratak_opis), $vodic->slug);
            $this->assertNotSame('', trim($vodic->tekst), $vodic->slug);
            $this->assertNotEmpty($vodic->koraci, $vodic->slug);
            $this->assertNotSame('', trim((string) $vodic->beleska), $vodic->slug);
        }

        $this->assertSame(11, count(array_unique(array_column(VodiciSeeder::vodici(), 'slug'))));
    }

    #[Test]
    public function seeder_pokrenut_dvaput_ne_pravi_duplikate(): void
    {
        $this->seed(VodiciSeeder::class);
        $this->seed(VodiciSeeder::class);

        $this->assertSame(11, Vodic::query()->count());
    }

    // Ogledalo: vodič koji je Ivan izmenio i objavio ostaje kakav je, a obrisan se vraća.
    #[Test]
    public function seeder_ne_gazi_izmenjen_vodic_a_dodaje_samo_slug_koji_fali(): void
    {
        $this->seed(VodiciSeeder::class);
        Vodic::query()->where('slug', 'kako-napisati-prvi-cv')->update(['tekst' => 'Ivanova verzija.', 'status' => StatusObjave::Objavljeno]);
        Vodic::query()->where('slug', 'kako-poslati-prijavu-online')->delete();

        $this->seed(VodiciSeeder::class);

        $izmenjen = Vodic::query()->where('slug', 'kako-napisati-prvi-cv')->sole();

        $this->assertSame('Ivanova verzija.', $izmenjen->tekst);
        $this->assertSame(StatusObjave::Objavljeno, $izmenjen->status);
        $this->assertSame(StatusObjave::Nacrt, Vodic::query()->where('slug', 'kako-poslati-prijavu-online')->sole()->status);
        $this->assertSame(11, Vodic::query()->count());
    }
}
