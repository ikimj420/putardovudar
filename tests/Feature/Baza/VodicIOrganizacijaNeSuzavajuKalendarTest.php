<?php

namespace Tests\Feature\Baza;

use App\Models\KalendarStavka;
use App\Services\Pomocnik\Pomocnik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 103/T1 (Ivan „ok", 05.11.2026., PITANJA-102 1): „vodič" i „organizacija" ne moraju da pišu ni u stavci kalendara, isto kao u
// pretrazi vodiča i organizacija (102/T1). Prvi formular je onaj koji je pravi Claude vratio za #32 u 2. prolazu banke (PROBA-PAKET-102.md).
#[Group('baza')]
class VodicIOrganizacijaNeSuzavajuKalendarTest extends BazaTestCase
{
    use RefreshDatabase;

    private const UPIS_PRVI_KRUG = 'Upis u srednju školu, prijava za prvi krug';

    private const UPIS_STARIJI = 'Upis u srednju školu za starije od 17 godina';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
        // Ponovljen Http::fake za istu adresu se ne računa, pa jedan lažni model čita formular pri svakom pozivu.
        Http::fake([LazniClaude::ADRESA => fn () => LazniClaude::odgovor(json_encode($this->forma + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []], JSON_UNESCAPED_UNICODE))]);
    }

    /** @var array<string, mixed> */
    private array $forma = [];

    /** @param  array<string, mixed>  $dopuna */
    private function stavka(string $naziv, array $dopuna = []): KalendarStavka
    {
        return KalendarStavka::factory()->objavljena()->create([...[
            'naziv' => $naziv, 'ko_daje' => 'Ministarstvo prosvete', 'otvoreno_do' => '2026-06-01', 'link_izvora' => 'https://prosveta.test/'.md5($naziv), 'napomena' => null,
        ], ...$dopuna]);
    }

    /**
     * @param  array<string, mixed>  $forma
     * @return list<string>
     */
    private function kalendar(string $pitanje, array $forma): array
    {
        $this->forma = $forma;

        return app(Pomocnik::class)->pretrazi($pitanje)->kalendar->pluck('naziv')->all();
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function upisSaOpstomReci(): array
    {
        return [
            '#32, 2. prolaz' => ['vodič za upis u školu', ['vrsta' => 'drugo', 'kljucne_reci' => ['vodič', 'upis', 'škola']]],
            'vodič u frazi' => ['vodič za upis u školu', ['kljucne_reci' => ['vodič za upis u školu']]],
            'množina' => ['Imate li vodiče za upis u školu?', ['kljucne_reci' => ['vodiče', 'upis u školu']]],
            'organizacija u frazi' => ['organizacija za upis u školu', ['kljucne_reci' => ['organizacija za upis u školu']]],
            'organizacije kao kome' => ['organizacije za upis u školu', ['kome' => 'organizacije', 'kljucne_reci' => ['upis', 'škola']]],
        ];
    }

    // Ostale reči i dalje sužavaju: Dositeja nema „upis" i ne dolazi.
    /** @param  array<string, mixed>  $forma */
    #[Test]
    #[DataProvider('upisSaOpstomReci')]
    public function vodic_za_upis_u_skolu_dobija_stavke_o_upisu_i_kad_model_vrati_rec_vodic(string $pitanje, array $forma): void
    {
        $this->stavka(self::UPIS_PRVI_KRUG);
        $this->stavka(self::UPIS_STARIJI, ['otvoreno_do' => '2026-05-01']);
        $this->stavka('Stipendija Dositeja', ['ko_daje' => 'Fond za mlade talente']);

        $this->assertSame([self::UPIS_PRVI_KRUG, self::UPIS_STARIJI], $this->kalendar($pitanje, $forma));
    }

    // Kad su „vodič" i „organizacija" jedine reči, pitanje nije o stavci, pa kalendar ostaje prazan kao i bez reči, iako ih stavka ima u tekstu.
    // Ogledalo: druga reč iz iste stavke je nalazi, i uz „organizacije" kojih u stavci nema.
    #[Test]
    public function samo_vodic_ili_organizacija_ne_izlistava_kalendar(): void
    {
        $this->stavka('Erasmus+: omladinske razmene', ['ko_daje' => 'Fondacija Tempus', 'napomena' => 'Prijavljuju se organizacije; vodič za prijavu je na sajtu Tempusa.']);
        $this->stavka('Erasmus+: male partnerske saradnje', ['ko_daje' => 'Fondacija Tempus', 'otvoreno_do' => '2026-03-01']);

        foreach ([['vodič'], ['organizacije'], ['vodiči', 'organizacija'], ['vodič za organizacije']] as $reci) {
            $this->assertSame([], $this->kalendar('Imate li '.implode(' i ', $reci).'?', ['kljucne_reci' => $reci]), implode(', ', $reci));
        }

        $this->assertSame(['Erasmus+: omladinske razmene', 'Erasmus+: male partnerske saradnje'], $this->kalendar('Erasmus za organizacije', ['kljucne_reci' => ['Erasmus', 'organizacije']]));
        $this->assertSame(['Erasmus+: male partnerske saradnje'], $this->kalendar('organizacije za partnerske saradnje', ['kljucne_reci' => ['organizacije', 'partnerske saradnje']]));
    }

    // Reč koja samo počinje kao „organ" ili „vod" nije „organizacija" ni „vodič": i dalje mora da piše u stavci.
    #[Test]
    public function rec_koja_lici_na_opstu_i_dalje_suzava(): void
    {
        $this->stavka('Organizovanje letnjeg kampa za decu');
        $this->stavka('Letnji kamp za decu', ['otvoreno_do' => '2026-05-01']);
        $this->stavka('Vodovod i kanalizacija: praksa', ['otvoreno_do' => '2026-04-01']);

        $this->assertSame(['Organizovanje letnjeg kampa za decu'], $this->kalendar('organizovanje kampa', ['kljucne_reci' => ['organizovanje kampa']]));
        $this->assertSame(['Vodovod i kanalizacija: praksa'], $this->kalendar('praksa u vodovodu', ['kljucne_reci' => ['vodovod']]));
        $this->assertSame(['Organizovanje letnjeg kampa za decu', 'Letnji kamp za decu'], $this->kalendar('kamp za decu', ['kljucne_reci' => ['kamp']]));
    }
}
