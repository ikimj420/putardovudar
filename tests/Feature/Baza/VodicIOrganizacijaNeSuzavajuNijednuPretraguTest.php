<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Organizacija;
use App\Models\Vodic;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\RezultatPretrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 102/T1 (Ivan „piši", 05.11.2026., PITANJA-101 1): „vodič" i „organizacija" ne moraju da pišu ni u naslovu vodiča ni u
// nazivu i uslugama organizacije. Formulari su oni koje je pravi Claude vratio za ta pitanja (PROBA-PAKET-101.md).
#[Group('baza')]
class VodicIOrganizacijaNeSuzavajuNijednuPretraguTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Vodic::factory()->objavljen()->create(['naslov' => 'Šta ako ti fali dokument?']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV']);
        Vodic::factory()->create(['naslov' => 'Dokument koji je nacrt', 'status' => StatusObjave::Nacrt]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Inicijativa A 11', 'online' => true, 'usluge' => ['Pravna pomoć', 'Savetovanje']]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Nacionalna služba za zapošljavanje', 'online' => true, 'usluge' => ['CV podrška']]);
    }

    /** @param  array<string, mixed>  $forma */
    private function pitaj(string $pitanje, array $forma): RezultatPretrage
    {
        Http::fake([LazniClaude::ADRESA => LazniClaude::odgovor(json_encode($forma + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], JSON_UNESCAPED_UNICODE))]);

        return app(Pomocnik::class)->pretrazi($pitanje);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function organizacijeZaDokumenta(): array
    {
        return [
            '#36' => ['organizacije za dokumenta', ['vrsta' => 'drugo', 'kljucne_reci' => ['organizacije', 'dokumenta']]],
            '#16' => ['Tražim organizacije za dokumenta', ['vrsta' => 'drugo', 'kljucne_reci' => ['dokumenta', 'organizacije']]],
            '#12' => ['Tražim organizaciju za dokumenta u Nišu', ['vrsta' => 'drugo', 'grad' => 'Niš', 'kljucne_reci' => ['organizaciju', 'dokumenta']]],
            'jedan izraz' => ['organizacije za dokumenta', ['vrsta' => 'drugo', 'kljucne_reci' => ['organizacije za dokumenta']]],
        ];
    }

    /** @param  array<string, mixed>  $forma */
    #[Test]
    #[DataProvider('organizacijeZaDokumenta')]
    public function organizacije_za_dokumenta_dobijaju_vodic_o_dokumentu(string $pitanje, array $forma): void
    {
        $rezultat = $this->pitaj($pitanje, $forma);

        $this->assertSame(['Šta ako ti fali dokument?'], $rezultat->vodici->pluck('naslov')->all());
        $this->assertFalse($rezultat->nemaPodatak());
        // Ogledalo: „dokumenta" i dalje sužava, pa ni vodič o CV-u ni organizacija bez reči „dokument" ne ulaze.
        $this->assertTrue($rezultat->organizacije->isEmpty());
        // Neprazan skup: objavljena su dva vodiča i dve organizacije, pa prazno i „sve" ne bi prošli.
        $this->assertSame(2, Vodic::javni()->count());
        $this->assertSame(2, Organizacija::javne()->count());
    }

    #[Test]
    public function vodic_za_cv_dobija_i_organizaciju_sa_tom_uslugom(): void
    {
        $rezultat = $this->pitaj('vodič za CV', ['kljucne_reci' => ['vodič za CV']]);

        $this->assertSame(['Kako napisati prvi CV'], $rezultat->vodici->pluck('naslov')->all());
        $this->assertSame(['Nacionalna služba za zapošljavanje'], $rezultat->organizacije->pluck('naziv')->all());
    }

    // Ogledalo: kad su jedine reči „vodič" ili „organizacija", i dalje se izlistava samo izrečena vrsta strane.
    #[Test]
    public function samo_organizacije_izlistava_organizacije_bez_vodica(): void
    {
        $rezultat = $this->pitaj('organizacije', ['kljucne_reci' => ['organizacije']]);

        $this->assertTrue($rezultat->vodici->isEmpty());
        $this->assertCount(2, $rezultat->organizacije);
    }

    #[Test]
    public function samo_vodici_izlistava_vodice_bez_organizacija(): void
    {
        $rezultat = $this->pitaj('vodiči', ['kljucne_reci' => ['vodiči']]);

        $this->assertSame(['Kako napisati prvi CV', 'Šta ako ti fali dokument?'], $rezultat->vodici->pluck('naslov')->all());
        $this->assertTrue($rezultat->organizacije->isEmpty());
    }
}
