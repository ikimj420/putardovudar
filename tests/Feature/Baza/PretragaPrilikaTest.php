<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 57 T8: jedno polje za pretragu (u naslovnom delu početne i na vrhu /prilike), po naslovu i kratkom opisu, samo kroz Prilika::javne(), zajedno sa izborom vrste.
// Obična forma, bez skripta.
#[Group('baza')]
class PretragaPrilikaTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));

        Prilika::factory()->objavljena()->create(['naslov' => 'Stipendija za studente', 'kratak_opis' => 'Za studente prve godine u Nišu.', 'vrsta' => VrstaPrilike::Stipendija, 'rok' => '2026-11-15']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'kratak_opis' => 'Puno radno vreme, Niš.', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-11-20']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Prodavac u radnji', 'kratak_opis' => 'Rad u maloprodaji.', 'vrsta' => VrstaPrilike::Posao, 'rok' => '2026-12-01']);
        Prilika::factory()->create(['naslov' => 'Stipendija Niš nacrt', 'kratak_opis' => 'Nacrt.', 'status' => StatusPrilike::Nacrt, 'rok' => '2026-11-15']);
        Prilika::factory()->objavljena()->create(['naslov' => 'Stipendija Niš istekla', 'kratak_opis' => 'Istekla.', 'rok' => '2026-10-01']);
    }

    /** @return list<string> naslovi javnih prilika koje pretraga nalazi, redom roka */
    private function nalazi(?string $upit, ?VrstaPrilike $vrsta = null): array
    {
        return Prilika::javne()->when($vrsta, fn ($q, VrstaPrilike $v) => $q->where('vrsta', $v))->pretraga($upit)->poRoku()->pluck('naslov')->all();
    }

    /** @return list<string> */
    private function naslovi(string $html): array
    {
        preg_match_all('#<article class="kartica redosled">\s*<h2><a [^>]*>([^<]+)</a></h2>#', $html, $nalazi);

        return $nalazi[1];
    }

    /** @return array<string, array{0: string|null, 1: list<string>}> */
    public static function upiti(): array
    {
        return [
            'po naslovu' => ['stipendija', ['Stipendija za studente']],
            'po kratkom opisu' => ['maloprodaji', ['Prodavac u radnji']],
            'velika slova i dijakritici' => ['NIŠ', ['Stipendija za studente', 'Radnik u skladištu']],
            'bez dijakritika' => ['nis', ['Stipendija za studente', 'Radnik u skladištu']],
            'padež' => ['Nišu', ['Stipendija za studente', 'Radnik u skladištu']],
            'drugi oblik reči' => ['stipendije', ['Stipendija za studente']],
            'sve reči moraju da stoje' => ['studente nis', ['Stipendija za studente']],
            'reči iz različitih delova' => ['skladistu nis', ['Radnik u skladištu']],
            'reči koje se ne nalaze zajedno' => ['studente skladistu', []],
            'ne traži se po vrsti' => ['posao', []],
            'ne postoji' => ['astronomija', []],
            'bez upita' => [null, ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
            'prazan upit' => ['', ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
            'džoker nije džoker' => ['%', ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
            'donja crta nije džoker' => ['_', ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
            'jedno slovo se ne računa' => ['u', ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
            'jedno slovo kog nema u tekstu se ne računa' => ['x', ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
            'samo znaci' => ['!!! ???', ['Stipendija za studente', 'Radnik u skladištu', 'Prodavac u radnji']],
        ];
    }

    /** @param  list<string>  $ocekivano */
    #[Test]
    #[DataProvider('upiti')]
    public function pretraga_trazi_po_naslovu_i_kratkom_opisu(?string $upit, array $ocekivano): void
    {
        $this->assertSame($ocekivano, $this->nalazi($upit));
    }

    // Nacrt i prilika kojoj je rok prošao nikad ne ulaze, ma kako upit pogađao njihov naslov (merilo: takve prilike u bazi postoje).
    #[Test]
    public function nacrt_i_prosao_rok_nikad_ne_ulaze(): void
    {
        $this->assertSame(2, Prilika::query()->where('naslov', 'like', '%Niš%')->count());
        $this->assertSame(5, Prilika::query()->count());
        $this->assertNotContains('Stipendija Niš nacrt', $this->nalazi('nacrt'));
        $this->assertNotContains('Stipendija Niš istekla', $this->nalazi('istekla'));
        $this->assertSame([], $this->nalazi('nacrt istekla'));
    }

    #[Test]
    public function upit_se_skracuje_na_osam_reci(): void
    {
        $this->assertCount(8, Prilika::reciZaPretragu('prva druga treca cetvrta peta sesta sedma osma deveta deseta'));
        $this->assertSame(['stipend'], Prilika::reciZaPretragu('Stipendije stipendija'));
    }

    // Strana: pretraga radi zajedno sa izborom vrste, a stranu i adresu čuva.
    #[Test]
    public function strana_prilika_trazi_zajedno_sa_vrstom(): void
    {
        $sve = $this->naslovi($this->get(route('prilike.index', ['q' => 'nisu']))->assertOk()->getContent());
        $this->assertSame(['Stipendija za studente', 'Radnik u skladištu'], $sve);

        $this->assertSame(['Stipendija za studente'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'stipendija', 'q' => 'nis']))->getContent()));
        $this->assertSame(['Radnik u skladištu'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao', 'q' => 'nis']))->getContent()));
        $this->assertSame([], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao', 'q' => 'studente']))->getContent()));
        // Ogledalo: bez pretrage vrsta radi kao do sada.
        $this->assertSame(['Radnik u skladištu', 'Prodavac u radnji'], $this->naslovi($this->get(route('prilike.index', ['vrsta' => 'posao']))->getContent()));
    }

    #[Test]
    public function bez_rezultata_stoji_poruka_o_pretrazi_a_bez_prilika_poruka_o_prilikama(): void
    {
        $this->assertStringContainsString('<p class="prazno">'.PrikazPrilike::NEMA_REZULTATA.'</p>', $this->get(route('prilike.index', ['q' => 'astronomija']))->assertOk()->getContent());
        $this->assertStringNotContainsString(PrikazPrilike::NEMA_PRILIKA, $this->get(route('prilike.index', ['q' => 'astronomija']))->getContent());

        Prilika::query()->delete();
        $prazno = $this->get(route('prilike.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<p class="prazno">'.PrikazPrilike::NEMA_PRILIKA.'</p>', $prazno);
        $this->assertStringNotContainsString(PrikazPrilike::NEMA_REZULTATA, $prazno);
    }

    #[Test]
    public function forma_je_obicna_get_forma_na_pocetnoj_i_na_vrhu_spiska(): void
    {
        $forma = '<form class="pretraga" method="get" action="'.route('prilike.index').'" role="search">';

        foreach ([route('pocetna'), route('prilike.index')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, $forma), $adresa);
            $this->assertStringContainsString('<input type="search" name="q" value="" maxlength="100" placeholder="'.PrikazPrilike::PRETRAGA_PRIMER.'" aria-label="'.PrikazPrilike::PRETRAGA_NAZIV.'">', $html, $adresa);
            $this->assertStringContainsString('<button class="dugme dugme-glavno dugme-malo" type="submit">'.PrikazPrilike::PRETRAGA_DUGME.'</button>', $html, $adresa);
            // Bez skripta: nema obrade događaja ni drugih podataka u formi.
            preg_match('#<form class="pretraga".*?</form>#s', $html, $unutra);
            $this->assertStringNotContainsString('onsubmit', $unutra[0], $adresa);
            $this->assertStringNotContainsString('name="vrsta"', $unutra[0], $adresa);
        }

        // Polje je u naslovnom delu: na početnoj u hero, na spisku u traci.
        preg_match('#<section class="hero zrno">(.*?)</section>#s', $this->get(route('pocetna'))->getContent(), $hero);
        $this->assertStringContainsString($forma, $hero[1]);
        preg_match('#<section class="naslovna-traka">(.*?)</section>#s', $this->get(route('prilike.index'))->getContent(), $traka);
        $this->assertStringContainsString($forma, $traka[1]);
    }

    // Izabrana vrsta ostaje uz pretragu (skriveno polje), upisano ostaje u polju i ispisuje se kao tekst; predugačak upit se skraćuje.
    #[Test]
    public function izabrana_vrsta_i_upisano_ostaju_a_upisano_se_ispisuje_kao_tekst(): void
    {
        $html = $this->get(route('prilike.index', ['vrsta' => 'posao', 'q' => 'nis']))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="vrsta" value="posao">', $html);
        $this->assertStringContainsString('name="q" value="nis"', $html);

        $napad = '"><script>alert(1)</script>';
        $html = $this->get(route('prilike.index', ['q' => $napad]))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);

        $dugacak = str_repeat('a', 150);
        $html = $this->get(route('prilike.index', ['q' => $dugacak]))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.str_repeat('a', 100).'"', $html);
        $this->assertStringNotContainsString(str_repeat('a', 101), $html);

        // Upit koji nije tekst (niz) se ne čita.
        $this->get('/prilike?q[]=nis')->assertOk();
    }

    #[Test]
    public function dugmad_vrsta_i_sve_prilike_cuvaju_pretragu(): void
    {
        $html = $this->get(route('prilike.index', ['q' => 'nis']))->assertOk()->getContent();

        // U atributu se & piše kao &amp;.
        $this->assertStringContainsString('href="'.e(route('prilike.index', ['q' => 'nis'])).'"', $html);
        $this->assertStringContainsString('href="'.e(route('prilike.index', ['vrsta' => 'posao', 'q' => 'nis'])).'"', $html);
        $this->assertStringContainsString('href="'.e(route('prilike.index', ['vrsta' => 'stipendija', 'q' => 'nis'])).'"', $html);
        // Ogledalo: bez pretrage dugmad ostaju kao do sada.
        $this->assertStringContainsString('href="'.route('prilike.index', ['vrsta' => 'posao']).'"', $this->get(route('prilike.index'))->getContent());
    }

    #[Test]
    public function stil_pravi_staklenu_formu_u_naslovnom_delu_sa_vidljivim_fokusom(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.pretraga \{[^}]*max-width: 640px;[^}]*background: rgba\(255, 255, 255, 0\.12\);[^}]*border: 1px solid rgba\(255, 255, 255, 0\.28\);[^}]*border-radius: 20px;[^}]*backdrop-filter: blur\(18px\) saturate\(1\.3\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.pretraga input\[type="search"\] \{[^}]*min-height: 46px;[^}]*background: transparent;[^}]*color: #fff;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.pretraga input\[type="search"\]:focus-visible \{[^}]*outline: 3px solid var\(--zlato\);/', $stil);
        // Preglednikovo plavo dugme za brisanje se ne crta.
        $this->assertMatchesRegularExpression('/\n\.pretraga input\[type="search"\]::-webkit-search-cancel-button \{\s*-webkit-appearance: none;\s*appearance: none;/', $stil);
        // U traci stranice polje ide posle naslova i oznaka, ne pre putanje.
        $this->assertMatchesRegularExpression('/\n\.naslovna-traka \.pretraga \{\s*order: 5;/', $stil);
    }
}
