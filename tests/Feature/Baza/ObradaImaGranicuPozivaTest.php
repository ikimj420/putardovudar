<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\Podrska\LazniClaude;

// Paket 95/T5 (PITANJA-92 5): uvoz:obradi pravi najviše onoliko poziva modela koliko piše u podešavanju (claude.najvise_poziva_po_prolazu); posle toga staje, a ostatak čeka sledeći prolaz.
#[Group('baza')]
class ObradaImaGranicuPozivaTest extends BazaTestCase
{
    use BezLokalnogOkruzenja, RefreshDatabase;

    private const CLAUDE = LazniClaude::ADRESA;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
        config()->set('uvoz.izvori', [['ime' => 'Izvor A', 'adresa' => 'https://a.test/feed']]);
        config()->set('claude.kljuc', 'probni-kljuc-nije-pravi-123');
    }

    /** @return list<Prilika> */
    private function nacrti(int $koliko): array
    {
        return array_map(fn (int $broj) => Prilika::factory()->create([
            'naslov' => 'Konkurs '.$broj, 'vrsta' => VrstaPrilike::Drugo, 'rok' => null, 'naziv_izvora' => 'Izvor A', 'link_izvora' => 'https://a.test/p'.$broj,
        ]), range(1, $koliko));
    }

    /** @param  list<int>  $bezStrane  brojevi nacrta čija strana vraća 404 */
    private function mreza(int $koliko, array $bezStrane = []): void
    {
        $forma = json_encode([
            'vazi_pravilo' => true, 'razlog' => 'Konkurs za studente.', 'vrsta' => 'konkurs', 'rok' => '2026-11-20', 'citat' => 'Rok za prijavu je 20. novembra 2026. godine',
            'grupe' => [['grupa' => 'studenti', 'citat' => 'Konkurs je otvoren za sve studente']],
        ], JSON_UNESCAPED_UNICODE);

        $fake = [
            self::CLAUDE => LazniClaude::odgovor($forma),
        ];

        foreach (range(1, $koliko) as $broj) {
            $fake['https://a.test/p'.$broj] = in_array($broj, $bezStrane, true)
                ? Http::response('', 404)
                : Http::response('<html><body><h1>Konkurs</h1><p>Rok za prijavu je 20. novembra 2026. godine.</p><p>Konkurs je otvoren za sve studente.</p></body></html>');
        }

        Http::fake($fake);
    }

    private function poziva(string $adresa): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === $adresa)->count();
    }

    private function neobradjenih(): int
    {
        return Prilika::query()->whereNull('obradeno_at')->count();
    }

    #[Test]
    public function obrada_staje_na_granici_a_ostatak_ceka_sledeci_prolaz(): void
    {
        config()->set('claude.najvise_poziva_po_prolazu', 2);
        $this->nacrti(5);
        $this->mreza(5);

        $this->assertSame(0, Artisan::call('uvoz:obradi'));
        $izlaz = Artisan::output();

        $this->assertSame(1, substr_count($izlaz, 'Granica od 2 poziva modela po prolazu je dostignuta; obrada staje. Čeka sledeći prolaz: 3.'));
        $this->assertSame(1, substr_count($izlaz, 'Granica od'));
        $this->assertStringContainsString('Obrađeno nacrta: 2. Objavljeno: 2.', $izlaz);

        $this->assertSame(2, $this->poziva(self::CLAUDE));
        $this->assertSame(3, $this->neobradjenih());

        // Sledeći prolaz nastavlja gde je stalo, pa treći završava poslednji nacrt bez poruke o granici.
        $this->artisan('uvoz:obradi')->expectsOutputToContain('Čeka sledeći prolaz: 1.')->assertSuccessful();
        $this->assertSame(1, $this->neobradjenih());

        $this->artisan('uvoz:obradi')->doesntExpectOutputToContain('Granica od')->expectsOutputToContain('Obrađeno nacrta: 1. Objavljeno: 1.')->assertSuccessful();
        $this->assertSame(0, $this->neobradjenih());
        $this->assertSame(5, $this->poziva(self::CLAUDE));
    }

    // Ogledalo: granica koja nije dostignuta ne staje ništa i ne piše ništa; tačno onoliko nacrta koliko je granica prolazi do kraja.
    #[Test]
    public function ispod_granice_i_tacno_na_granici_obrada_ide_do_kraja_bez_poruke(): void
    {
        config()->set('claude.najvise_poziva_po_prolazu', 5);
        $this->nacrti(5);
        $this->mreza(5);

        $this->artisan('uvoz:obradi')->doesntExpectOutputToContain('Granica od')->expectsOutputToContain('Obrađeno nacrta: 5. Objavljeno: 5.')->assertSuccessful();

        $this->assertSame(0, $this->neobradjenih());
        $this->assertSame(5, $this->poziva(self::CLAUDE));
    }

    // Broje se pozivi modela, ne nacrti: strana koja ne stiže ne troši poziv.
    #[Test]
    public function nacrti_bez_strane_ne_trose_pozive(): void
    {
        config()->set('claude.najvise_poziva_po_prolazu', 1);
        $this->nacrti(4);
        $this->mreza(4, [1, 2, 3]);

        $this->artisan('uvoz:obradi')
            ->doesntExpectOutputToContain('Granica od')
            ->expectsOutputToContain('Obrađeno nacrta: 1. Objavljeno: 1. Ostalo nacrt: 0. Nije obrađeno: 3.')
            ->assertSuccessful();

        $this->assertSame(1, $this->poziva(self::CLAUDE));
    }

    #[Test]
    public function granica_od_jednog_poziva_ostavlja_ostale_nacrte_neobradjene(): void
    {
        config()->set('claude.najvise_poziva_po_prolazu', 1);
        $this->nacrti(3);
        $this->mreza(3);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('Granica od 1 poziva modela po prolazu je dostignuta; obrada staje. Čeka sledeći prolaz: 2.')->assertSuccessful();

        $this->assertSame(1, $this->poziva(self::CLAUDE));
        $this->assertSame(2, $this->neobradjenih());
    }

    // Granica manja od jedan ne gasi obradu: ostaje jedan poziv po prolazu, da pogrešno podešavanje bude vidljivo, a ne tiho zaustavljanje.
    #[Test]
    public function granica_manja_od_jedan_ostavlja_jedan_poziv(): void
    {
        config()->set('claude.najvise_poziva_po_prolazu', 0);
        $this->nacrti(2);
        $this->mreza(2);

        $this->artisan('uvoz:obradi')->expectsOutputToContain('Granica od 1 poziva modela po prolazu je dostignuta; obrada staje. Čeka sledeći prolaz: 1.')->assertSuccessful();

        $this->assertSame(1, $this->poziva(self::CLAUDE));
    }
}
