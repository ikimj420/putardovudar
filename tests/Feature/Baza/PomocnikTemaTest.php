<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Organizacija;
use App\Models\Vodic;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\PretragaOrganizacija;
use App\Services\Pomocnik\PretragaVodica;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 30: kad pitanje traži baš vodič ili organizaciju („vodič za konkurse"), reč koja je ponavljala vrstu prilike je tema.
#[Group('baza')]
class PomocnikTemaTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @param  array<string, mixed>  $forma */
    private function pitaj(string $pitanje, array $forma): RezultatPretrage
    {
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => json_encode($forma + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], JSON_UNESCAPED_UNICODE)]])]);

        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    private function vodici(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako razumeti uslove konkursa']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za stipendiju']);
    }

    // #34 iz paketa 25: „konkurse" je ponavljalo vrstu „konkurs", pa je otpalo i vodiči su ostali bez teme.
    #[Test]
    public function vodic_za_konkurse_nalazi_vodic_o_konkursima(): void
    {
        $this->vodici();

        $rezultat = $this->pitaj('vodič za konkurse', ['vrsta' => 'konkurs', 'kljucne_reci' => ['vodič', 'konkurse']]);

        $this->assertSame(['vodič'], $rezultat->formular->kljucneReci);
        $this->assertSame(['vodič', 'konkurse'], $rezultat->formular->sveKljucneReci);
        $this->assertSame(['Kako razumeti uslove konkursa'], $rezultat->vodici->pluck('naslov')->all());
    }

    // Model ume da vrati i ceo izraz u jednoj ključnoj reči; predlog u njemu ne sme da sakrije vodič.
    #[Test]
    public function vodic_za_konkurse_kao_jedan_izraz_nalazi_isti_vodic(): void
    {
        $this->vodici();

        $rezultat = $this->pitaj('Imate li vodič za konkurse?', ['vrsta' => 'konkurs', 'kljucne_reci' => ['vodič za konkurse']]);

        $this->assertSame(['Kako razumeti uslove konkursa'], $rezultat->vodici->pluck('naslov')->all());
    }

    // Ogledalo: bez reči „vodič" reč koja ponavlja vrstu i dalje ne sužava, pa „stipendiju" ne dovodi vodiče ni organizacije.
    #[Test]
    public function bez_reci_vodic_ponovljena_vrsta_ne_dovodi_vodice_ni_organizacije(): void
    {
        $this->vodici();
        Organizacija::factory()->objavljena()->create(['naziv' => 'Fondacija Tempus', 'usluge' => ['Pomoć oko stipendija']]);

        $rezultat = $this->pitaj('Tražim stipendiju', ['vrsta' => 'stipendija', 'kljucne_reci' => ['stipendiju']]);

        $this->assertSame([], $rezultat->formular->kljucneReci);
        $this->assertSame(['stipendiju'], $rezultat->formular->sveKljucneReci);
        $this->assertTrue($rezultat->vodici->isEmpty());
        $this->assertTrue($rezultat->organizacije->isEmpty());
        $this->assertTrue($rezultat->nemaPodatak());
    }

    #[Test]
    public function organizacija_za_stipendije_nalazi_organizacije_sa_tom_uslugom(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Fondacija Tempus', 'usluge' => ['Pomoć oko stipendija', 'Savetovanje']]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Nacionalna služba za zapošljavanje', 'usluge' => ['CV podrška']]);
        Organizacija::factory()->create(['naziv' => 'Nacrt za stipendije', 'usluge' => ['Pomoć oko stipendija'], 'status' => StatusObjave::Nacrt]);

        $rezultat = $this->pitaj('organizacija za stipendije', ['vrsta' => 'stipendija', 'kljucne_reci' => ['organizacija', 'stipendije']]);

        $this->assertSame(['Fondacija Tempus'], $rezultat->organizacije->pluck('naziv')->all());
        // Ogledalo: ista tema bez reči „organizacija" ne dovodi organizacije.
        $this->assertTrue($this->pitaj('stipendije', ['vrsta' => 'stipendija', 'kljucne_reci' => ['stipendije']])->organizacije->isEmpty());
    }

    // Grad nije tema vodiča ni organizacija (organizacije ga imaju posebno): „Niš" ne ulazi u reči za njih.
    #[Test]
    public function grad_ne_ulazi_u_reci_za_vodice_i_organizacije(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Nacionalna služba za zapošljavanje', 'online' => true, 'usluge' => ['CV podrška']]);

        $rezultat = $this->pitaj('organizacije u Nišu', ['grad' => 'Niš', 'kljucne_reci' => ['organizacije', 'Niš']]);

        $this->assertSame(['organizacije'], $rezultat->formular->sveKljucneReci);
        $this->assertSame(['Nacionalna služba za zapošljavanje'], $rezultat->organizacije->pluck('naziv')->all());
    }

    #[Test]
    public function formular_sklopljen_u_kodu_koristi_iste_reci_za_sve(): void
    {
        $formular = new Formular(kljucneReci: ['CV']);

        $this->assertSame(['CV'], $formular->sveKljucneReci);
        $this->assertSame(['CV'], $formular->reciZa('vodic'));
        $this->assertCount(0, app(PretragaVodica::class)->pronadji($formular));
        $this->assertCount(0, app(PretragaOrganizacija::class)->pronadji($formular));
    }
}
