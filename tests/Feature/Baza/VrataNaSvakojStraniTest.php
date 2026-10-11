<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 62/T2: ista vrata (jedna komponenta) u naslovnom delu svake javne strane; sliku i oznake piše samo komponenta.
#[Group('baza')]
class VrataNaSvakojStraniTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    private const LUK = '<path d="M52 242V118a58 58 0 0 1 116 0v124z"';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, string> strana => adresa; neprazan skup: svih jedanaest javnih strana */
    private function adrese(): array
    {
        $prilika = Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-12-20']);
        $vodic = Vodic::factory()->objavljen()->create();
        $organizacija = Organizacija::factory()->objavljena()->create();
        $sablon = Sablon::factory()->objavljen()->create();

        return [
            'početna' => route('pocetna'),
            'prilike' => route('prilike.index'),
            'vodiči' => route('vodici.index'),
            'organizacije' => route('organizacije.index'),
            'kalendar' => route('kalendar.index'),
            'pomoćnik' => route('pomocnik.index'),
            'prilika' => route('prilike.show', $prilika->slug),
            'vodič' => route('vodici.show', $vodic->slug),
            'organizacija' => route('organizacije.show', $organizacija->slug),
            'šablon' => route('pomocnik.napisi.forma', $sablon->slug),
        ];
    }

    private function naslovniDeo(string $html): string
    {
        preg_match('#<section class="(?:hero zrno|naslovna-traka)">(.*?)</section>\s*<div class="sirina sadrzaj">#s', $html, $nalaz);

        return $nalaz[1] ?? '';
    }

    #[Test]
    public function svaka_javna_strana_ima_jedna_vrata_u_naslovnom_delu(): void
    {
        $adrese = $this->adrese();

        $this->assertCount(10, $adrese);
        foreach ($adrese as $strana => $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();
            $deo = $this->naslovniDeo($html);

            $this->assertNotSame('', $deo, $strana);
            $this->assertSame(1, substr_count($html, '<svg class="vrata"'), $strana);
            $this->assertSame(1, substr_count($deo, '<div class="vrata-okvir">'), $strana);
            $this->assertSame(1, substr_count($html, self::LUK), $strana);
            // Vrata su u naslovnom delu, ne u sadržaju strane.
            $this->assertStringNotContainsString('vrata-okvir', substr($html, (int) strpos($html, '<div class="sirina sadrzaj">')), $strana);
            // Svaki id na strani je jedinstven (vrata ne smeju da ponove id gradijenta ni da se sudare sa znakom u zaglavlju).
            preg_match_all('/\bid="([^"]+)"/', $html, $idovi);
            $this->assertSame([], array_keys(array_filter(array_count_values($idovi[1]), fn (int $broj) => $broj > 1)), $strana);
        }
    }

    // Ogledalo: strana „nema strane" nema naslovni deo, pa nema ni vrata; i prozor pomoćnika nije deo vrata.
    #[Test]
    public function strana_bez_naslovnog_dela_nema_vrata(): void
    {
        $this->adrese();

        $html = $this->get(route('prilike.show', 'ne-postoji'))->assertNotFound()->getContent();

        $this->assertStringNotContainsString('class="vrata', $html);
        $this->assertStringNotContainsString('vrata-okvir', $html);
    }

    // Napisan tekst (odgovor sa šablona) je ista strana sa istim naslovnim delom.
    #[Test]
    public function napisan_tekst_ima_ista_vrata(): void
    {
        $sablon = Sablon::factory()->objavljen()->create();
        Http::fake(['*' => Http::response('', 500)]);

        $html = $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['ime' => 'Ana']])->assertOk()->getContent();

        $this->assertSame(1, substr_count($this->naslovniDeo($html), '<div class="vrata-okvir">'));
    }

    // Oznake na vratima su iste na svakoj strani: samo vrste koje imaju javnu priliku, redom vrsta; nacrt i prošao rok ne računaju.
    #[Test]
    public function oznake_su_iste_na_svakoj_strani_i_samo_za_vrste_sa_javnom_prilikom(): void
    {
        $adrese = $this->adrese();
        Prilika::factory()->create(['vrsta' => VrstaPrilike::Konkurs, 'status' => StatusPrilike::Nacrt, 'rok' => '2026-12-15']);
        Prilika::factory()->objavljena()->create(['vrsta' => VrstaPrilike::Obuka, 'rok' => '2026-10-01']);

        foreach ($adrese as $strana => $adresa) {
            preg_match_all('#<span class="vrsta-tacka" data-vrsta="([a-z]+)"#', $this->get($adresa)->assertOk()->getContent(), $oznake);

            $this->assertSame(['posao', 'stipendija'], $oznake[1], $strana);
        }
    }

    // Jedno mesto u kodu: sliku vrata (luk) piše samo komponenta; nijedan drugi pogled ni klasa ne crta vrata.
    #[Test]
    public function sliku_vrata_pise_samo_komponenta(): void
    {
        $nalazi = [];
        foreach ([base_path('app'), resource_path('views')] as $koren) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($koren, \FilesystemIterator::SKIP_DOTS)) as $fajl) {
                $sadrzaj = (string) file_get_contents($fajl->getPathname());

                if (str_contains($sadrzaj, 'M52 242V118a58') || str_contains($sadrzaj, 'class="vrata"')) {
                    $nalazi[] = str_replace(base_path().'/', '', $fajl->getPathname());
                }
            }
        }

        $this->assertSame(['resources/views/javno/vrata.blade.php'], $nalazi);
        // Pogledi je zovu kao komponentu: raspored za naslovnu traku, početna u svom rasporedu.
        $this->assertStringContainsString('<x-vrata />', (string) file_get_contents(resource_path('views/javno/raspored.blade.php')));
        $this->assertStringContainsString('<x-vrata />', (string) file_get_contents(resource_path('views/javno/pocetna.blade.php')));
    }

    // Svetlo na podu je centrirano ispod vrata (cx 110, kao sredina slike), a krilo nema kvaku: bez pozadine stajala bi preko slova.
    #[Test]
    public function svetlo_na_podu_je_na_sredini_a_krilo_nema_kvaku(): void
    {
        $this->adrese();

        $html = $this->get(route('prilike.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<ellipse cx="110" cy="246" rx="92" ry="16" fill="url(#vrata-pod)"/>', $html);
        $this->assertStringNotContainsString('<ellipse cx="96"', $html);
        $this->assertStringNotContainsString('<circle cx="144" cy="158"', $html);
        // Ogledalo: krilo i okvir postoje.
        $this->assertStringContainsString('<path d="M138 67.2A58 58 0 0 1 168 118v124h-30z" fill="url(#vrata-krilo)"/>', $html);
        $this->assertStringContainsString('<path d="M40 242V116a70 70 0 0 1 140 0v126"', $html);
    }

    // Stil: na telefonu vrata na sredini, široka koliko sadržaj (najviše 380 px) i bez bledila; na laptopu 380 px desno od naslova; ne štampaju se.
    #[Test]
    public function stil_daje_vratima_punu_vidljivost_i_mesto_ispod_teksta_pa_desno(): void
    {
        $stil = $this->stilBezKomentara();
        $bezUpita = $this->stilBezUpita();

        $this->assertMatchesRegularExpression('/\n\.vrata-okvir \{[^}]*justify-self: center;[^}]*width: min\(100%, 380px\);/', $bezUpita);
        $this->assertMatchesRegularExpression('/\n\.vrata \{[^}]*display: block;[^}]*width: 100%;/', $bezUpita);
        // Puna vidljivost: nijedno pravilo vrata ne smanjuje neprozirnost i ne gasi događaje.
        preg_match_all('/\n\.vrata[a-z-]* \{([^}]*)\}/', $stil, $pravila);
        $this->assertNotEmpty($pravila[1]);
        foreach ($pravila[1] as $telo) {
            $this->assertStringNotContainsString('opacity', $telo);
            $this->assertStringNotContainsString('pointer-events', $telo);
        }
        // Telefon: naslovna traka je jedna kolona (tekst, pa vrata); laptop od 900 px: dve kolone, vrata 380 px desno.
        $this->assertMatchesRegularExpression('/\n\.traka-red \{[^}]*display: grid;/', $bezUpita);
        $this->assertMatchesRegularExpression('/@media \(min-width: 900px\) \{[^@]*\.traka-red \{[^}]*grid-template-columns: minmax\(0, 1fr\) 380px;[^}]*align-items: center;/', $stil);
        // Štampa: vrata se ne štampaju.
        $this->assertStringContainsString('.vrata-okvir,', substr($stil, (int) strpos($stil, '@media print')));
    }
}
