<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 62/T3: svetlo, krilo, oreol i svetlo na podu vrata su u boji strane (vrednosti iz uzora vrata-boje-predlog.html); slika ih čita iz promenljivih strane.
#[Group('baza')]
class BojaVrataPoStraniTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    private const ZLATNA = ['svetlo-1' => '#ffe7b0', 'svetlo-2' => '#f7b866', 'svetlo-3' => '#f0994c', 'krilo-1' => '#e7b53c', 'krilo-2' => '#c85a14', 'oreol' => '#f0994c', 'pod' => '#ffd9a0'];

    /** @return array<string, array{0: ?string, 1: array<string, string>}> strana (data-strana) i njene boje */
    public static function boje(): array
    {
        return [
            'početna, pomoćnik i ostale' => [null, self::ZLATNA],
            'prilike' => ['prilike', ['svetlo-1' => '#ffe2c6', 'svetlo-2' => '#f7a866', 'svetlo-3' => '#e07229', 'krilo-1' => '#e07229', 'krilo-2' => '#a8470f', 'oreol' => '#f0994c', 'pod' => '#ffc59a']],
            'vodiči' => ['vodici', ['svetlo-1' => '#f1e6ff', 'svetlo-2' => '#c9a2fb', 'svetlo-3' => '#8d4ceb', 'krilo-1' => '#7d36e0', 'krilo-2' => '#4a1499', 'oreol' => '#a85ff2', 'pod' => '#d9c2ff']],
            'organizacije' => ['organizacije', ['svetlo-1' => '#ffe0ef', 'svetlo-2' => '#e58ab9', 'svetlo-3' => '#b83c7e', 'krilo-1' => '#a82d6e', 'krilo-2' => '#6a1546', 'oreol' => '#b83c7e', 'pod' => '#f5b6d5']],
            'kalendar' => ['kalendar', ['svetlo-1' => '#fff6d6', 'svetlo-2' => '#f5d98a', 'svetlo-3' => '#e7b53c', 'krilo-1' => '#e7b53c', 'krilo-2' => '#b07f14', 'oreol' => '#e7b53c', 'pod' => '#fbe3a0']],
        ];
    }

    /** @return array<string, string> promenljive vrata koje važe na strani (osnovne, pa one koje strana menja) */
    private function boja(?string $strana): array
    {
        $stil = $this->stilBezKomentara();
        $telo = [];

        preg_match('/\n:root \{([^}]*)\}/', $stil, $osnovno);
        $this->assertNotEmpty($osnovno, 'u javno.css nema :root');
        $telo[] = $osnovno[1];

        if ($strana !== null) {
            preg_match('/\nbody\[data-strana="'.$strana.'"\] \{([^}]*)\}/', $stil, $strane);
            $this->assertNotEmpty($strane, $strana);
            $telo[] = $strane[1];
        }

        $boje = [];
        foreach ($telo as $deo) {
            preg_match_all('/--vrata-([a-z0-9-]+): (#[0-9a-f]{6});/', $deo, $nalazi, PREG_SET_ORDER);
            foreach ($nalazi as [, $ime, $vrednost]) {
                $boje[$ime] = $vrednost;
            }
        }
        ksort($boje);

        return $boje;
    }

    /** @param  array<string, string>  $ocekivano */
    #[Test]
    #[DataProvider('boje')]
    public function svaka_strana_ima_svoje_boje_svetla_krila_oreola_i_poda(?string $strana, array $ocekivano): void
    {
        ksort($ocekivano);

        $this->assertSame($ocekivano, $this->boja($strana));
    }

    // Strane i boje: prilike (i strana prilike) narandžasta, vodiči (i vodič) ljubičasta, organizacije bobica, kalendar zlato; početna, pomoćnik i šablon zlatno-narandžasta.
    #[Test]
    public function svaka_javna_strana_nosi_oznaku_svoje_boje(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
        $prilika = Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        $vodic = Vodic::factory()->objavljen()->create();
        $organizacija = Organizacija::factory()->objavljena()->create();
        $sablon = Sablon::factory()->objavljen()->create();

        $ocekivano = [
            route('pocetna') => null, route('pomocnik.index') => null, route('pomocnik.napisi.forma', $sablon->slug) => null,
            route('prilike.index') => 'prilike', route('prilike.show', $prilika->slug) => 'prilike',
            route('vodici.index') => 'vodici', route('vodici.show', $vodic->slug) => 'vodici',
            route('organizacije.index') => 'organizacije', route('organizacije.show', $organizacija->slug) => 'organizacije',
            route('kalendar.index') => 'kalendar',
        ];

        $viđeno = [];
        foreach ($ocekivano as $adresa => $strana) {
            preg_match('/<body[^>]*\bdata-strana="([a-z]+)"/', $this->get($adresa)->assertOk()->getContent(), $nalaz);
            $this->assertSame($strana, $nalaz[1] ?? null, $adresa);
            $viđeno[] = $nalaz[1] ?? 'zlatna';
        }

        // Neprazan skup: obišle su se sve četiri boje strana i podrazumevana.
        $this->assertEqualsCanonicalizing(['zlatna', 'prilike', 'vodici', 'organizacije', 'kalendar'], array_values(array_unique($viđeno)));
    }

    // Slika ne zna na kojoj je strani: nijedan prelaz u komponenti nema upisanu boju, svaki čita promenljivu preko klase koja ima pravilo u stilu.
    #[Test]
    public function slika_cita_boje_iz_promenljivih_a_ne_ima_svoje(): void
    {
        $pogled = (string) file_get_contents(resource_path('views/javno/vrata.blade.php'));
        $stil = $this->stilBezKomentara();

        $this->assertDoesNotMatchRegularExpression('/stop-color="/', $pogled);
        preg_match_all('/<stop class="(vrata-[a-z0-9-]+)"/', $pogled, $klase);
        $this->assertEqualsCanonicalizing(['vrata-svetlo-1', 'vrata-svetlo-2', 'vrata-svetlo-3', 'vrata-krilo-1', 'vrata-krilo-2', 'vrata-oreol', 'vrata-pod'], array_values(array_unique($klase[1])));

        foreach (array_unique($klase[1]) as $klasa) {
            $this->assertMatchesRegularExpression('/\n\.'.$klasa.' \{\s*stop-color: var\(--'.$klasa.'\);\s*\}/', $stil, $klasa);
        }
        // Oreol je tri stepena iste boje (providnost .5, .14, 0), a pod dva (.8, 0), kao u uzoru.
        $this->assertSame(['.5', '.14', '0'], $this->providnosti($pogled, 'vrata-oreol'));
        $this->assertSame(['.8', '0'], $this->providnosti($pogled, 'vrata-pod'));
    }

    /** @return list<string> */
    private function providnosti(string $pogled, string $klasa): array
    {
        preg_match_all('/<stop class="'.$klasa.'" offset="[^"]*" stop-opacity="([^"]*)"/', $pogled, $nalazi);

        return $nalazi[1];
    }
}
