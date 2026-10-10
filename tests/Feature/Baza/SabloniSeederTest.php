<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use App\Support\SklapanjeSablona;
use Database\Seeders\SabloniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

#[Group('baza')]
class SabloniSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeder_pravi_molbu_i_zahtev_oba_su_nacrt_i_nijedan_nije_javan(): void
    {
        $this->seed(SabloniSeeder::class);

        $this->assertSame(['molba', 'zahtev'], Sablon::query()->orderBy('id')->pluck('slug')->all());
        $this->assertSame(2, Sablon::query()->where('status', StatusObjave::Nacrt)->count());
        $this->assertSame(0, Sablon::javni()->count());
        $this->get(route('sabloni.index'))->assertOk()->assertDontSee('Molba');
        $this->get(route('sabloni.show', 'molba'))->assertNotFound();
    }

    // Svaki šablon ima sva polja koja tekst traži (inače se ne bi mogao objaviti), a opšti oblik dopisa ima sve delove iz paketa.
    #[Test]
    public function svako_mesto_u_tekstu_ima_polje_i_tekst_nema_tvrdnju_o_propisima(): void
    {
        $this->assertNotSame([], SabloniSeeder::sabloni());

        foreach (SabloniSeeder::sabloni() as $podaci) {
            $sablon = new Sablon($podaci);
            $kljucevi = SklapanjeSablona::kljuceUTekstu($podaci['tekst']);

            $this->assertNotSame([], $kljucevi, $podaci['slug']);
            $this->assertSame([], array_values(array_diff($kljucevi, $sablon->kljucevi())), $podaci['slug'].': mesto bez polja');
            $this->assertSame([], array_values(array_diff($sablon->kljucevi(), $kljucevi)), $podaci['slug'].': polje bez mesta u tekstu');
            // Podnosilac, primalac, naslov ili predmet, tekst, mesto i datum, potpis (ime na kraju).
            foreach (['ime', 'adresa', 'primalac', 'tekst', 'mesto', 'datum'] as $deo) {
                $this->assertContains($deo, $kljucevi, $podaci['slug'].': nema dela '.$deo);
            }
            $this->assertDoesNotMatchRegularExpression('/\b(?:član|zakon|propis|uredb|pravilnik)/ui', $podaci['tekst'], $podaci['slug'].': tvrdnja o propisima');
        }
    }

    // Prazan obrazac daje dopis sa linijama za olovku i nijednom viticastom zagradom, a popunjen ima sve upisano.
    #[Test]
    public function prazan_i_popunjen_obrazac_oba_daju_tekst_bez_viticastih_zagrada(): void
    {
        $this->seed(SabloniSeeder::class);

        foreach (Sablon::query()->get() as $sablon) {
            $prazan = SklapanjeSablona::sklopi($sablon, []);
            $popunjen = SklapanjeSablona::sklopi($sablon, array_fill_keys($sablon->kljucevi(), 'Upisano'));

            $this->assertStringNotContainsString('{', $prazan, $sablon->slug);
            $this->assertStringContainsString(SklapanjeSablona::PRAZNO, $prazan, $sablon->slug);
            $this->assertStringNotContainsString('{', $popunjen, $sablon->slug);
            $this->assertStringNotContainsString(SklapanjeSablona::PRAZNO, $popunjen, $sablon->slug);
        }
    }

    #[Test]
    public function seeder_pokrenut_dvaput_ne_pravi_duplikate_i_ne_gazi_izmenjen_sablon(): void
    {
        $this->seed(SabloniSeeder::class);
        Sablon::query()->where('slug', 'molba')->firstOrFail()->update(['naslov' => 'Izmenjena molba', 'status' => StatusObjave::Objavljeno]);
        $this->seed(SabloniSeeder::class);

        $this->assertSame(2, Sablon::query()->count());
        $this->assertSame('Izmenjena molba', Sablon::query()->where('slug', 'molba')->firstOrFail()->naslov);
        $this->assertSame(StatusObjave::Objavljeno, Sablon::query()->where('slug', 'molba')->firstOrFail()->status);

        // Obrisan se vraća, kao kod vodiča.
        Sablon::query()->where('slug', 'zahtev')->delete();
        $this->seed(SabloniSeeder::class);
        $this->assertSame(2, Sablon::query()->count());
    }

    // Objavljeni šablon iz seedera radi do kraja kroz javnu stranu.
    #[Test]
    public function objavljena_molba_se_popunjava_kroz_javnu_stranu(): void
    {
        $this->seed(SabloniSeeder::class);
        Sablon::query()->where('slug', 'molba')->firstOrFail()->update(['status' => StatusObjave::Objavljeno]);

        $this->get(route('sabloni.show', 'molba'))->assertOk()->assertSee('Naslov molbe');
        $html = $this->post(route('sabloni.sklopi', 'molba'), ['ime' => 'Ana Anić', 'primalac' => 'Školi', 'naslov' => 'Molba za upis', 'tekst' => 'Molim za upis deteta.', 'mesto' => 'Leskovac', 'datum' => '10.10.2026.'])->assertOk()->getContent();

        $this->assertStringContainsString("Ana Anić\n", html_entity_decode($html));
        $this->assertStringContainsString('Leskovac, 10.10.2026.', html_entity_decode($html));
    }
}
