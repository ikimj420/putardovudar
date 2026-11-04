<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;
use Tests\Podrska\LazniClaude;

// Paket 64/T6: „Napiši moj" zna rod iz imena; kad ne zna, pita jedno pitanje; ispod teksta je link za promenu roda; šablon kad model ne radi poštuje rod.
#[Group('baza')]
class NapisiMojRodTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    private const MODEL = LazniClaude::ADRESA;

    private const PITANJE = 'Da pišem „radio sam" ili „radila sam"?';

    private const ZENSKI_RED = 'Rod autora je ženski';

    private const MUSKI_RED = 'Rod autora je muški';

    private bool $modelRadi = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // Tekst modela ponavlja upisano ime, jer ga provera traži (paket 67); šablon bez polja „ime" dobija tekst bez imena.
        Http::fake([self::MODEL => function (Request $zahtev) {
            preg_match('/\{ime\} \(Ime i prezime\): (.+)/', (string) LazniClaude::poruka($zahtev), $ime);

            return $this->modelRadi ? LazniClaude::odgovor(rtrim('Napisan tekst. '.($ime[1] ?? ''))) : throw new ConnectionException('nema veze');
        }]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function sablon(array $dopuna = []): Sablon
    {
        return Sablon::factory()->objavljen()->create([...[
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'iskustvo', 'oznaka' => 'Iskustvo', 'objasnjenje' => null, 'dugo' => true],
            ],
            'tekst' => "Poštovani,\n\n{iskustvo}\n\nS poštovanjem,\n{ime}",
        ], ...$dopuna]);
    }

    // Šablon sa mestima u dva roda: muški oblik pre kose crte, ženski posle nje.
    private function sablonSaRodom(): Sablon
    {
        return $this->sablon(['tekst' => "Poštovani,\n\nOglas sam {video/videla} na sajtu. Tamo sam {radio/radila} kao konobar.\n\n{iskustvo}\n\nS poštovanjem,\n{ime}"]);
    }

    /**
     * @param  array<string, mixed>  $polja
     * @param  array<string, mixed>  $dopuna
     */
    private function napisi(Sablon $sablon, array $polja, array $dopuna = [], bool $prozor = false): TestResponse
    {
        $zahtev = $prozor ? $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']) : $this;

        return $zahtev->post(route('pomocnik.napisi', $sablon->slug), ['polje' => $polja] + $dopuna);
    }

    private function napisan(string $html): string
    {
        preg_match('#<div class="sklopljen-tekst" id="napisan-tekst">(.*?)</div>#s', $html, $nalaz);

        return html_entity_decode($nalaz[1] ?? '');
    }

    // Uputstvo o rodu koje je stiglo modelu (poslednji poziv).
    private function rodUPoruci(): ?string
    {
        $poruka = Http::recorded()->last()[0]->data()['messages'][0]['content'] ?? '';

        return match (true) {
            str_contains($poruka, self::ZENSKI_RED) => 'zenski',
            str_contains($poruka, self::MUSKI_RED) => 'muski',
            default => null,
        };
    }

    private function jePitanje(string $html): bool
    {
        return str_contains($html, '<h2 id="rod-pitanje">'.htmlspecialchars(self::PITANJE).'</h2>');
    }

    private function linkZaPromenu(string $html): ?string
    {
        preg_match('#<button class="veza-dugme" type="submit" name="rod" value="(muski|zenski)">([^<]+)</button>#', $html, $nalaz);

        return isset($nalaz[1]) ? $nalaz[1].'|'.$nalaz[2] : null;
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> ime, rod koji model dobija, link koji se nudi */
    public static function poznataImena(): array
    {
        return [
            'Ana' => ['Ana Anić', 'zenski', 'muski|Promeni u muški rod'],
            'Marko' => ['Marko Anić', 'muski', 'zenski|Promeni u ženski rod'],
        ];
    }

    #[Test]
    #[DataProvider('poznataImena')]
    public function rod_se_zna_iz_imena_model_ga_dobija_a_ispod_teksta_je_link_za_suprotan(string $ime, string $rod, string $link): void
    {
        $html = $this->napisi($this->sablon(), ['ime' => $ime, 'iskustvo' => 'Radim u školi.'])->assertOk()->getContent();

        $this->assertSame('Napisan tekst. '.$ime, $this->napisan($html));
        $this->assertSame($rod, $this->rodUPoruci());
        $this->assertSame($link, $this->linkZaPromenu($html));
        $this->assertFalse($this->jePitanje($html));
        Http::assertSentCount(1);
    }

    #[Test]
    public function link_za_promenu_ponovo_pise_tekst_u_drugom_rodu_i_nudi_povratak(): void
    {
        $sablon = $this->sablon();

        $html = $this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => 'Radim u školi.'], ['rod' => 'muski'])->assertOk()->getContent();

        $this->assertSame('muski', $this->rodUPoruci());
        $this->assertSame('zenski|Promeni u ženski rod', $this->linkZaPromenu($html));
        $this->assertFalse($this->jePitanje($html));
        // Ogledalo: bez dugmeta isto ime daje ženski rod i link ka muškom.
        $this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => 'Radim u školi.'])->assertOk();
        $this->assertSame('zenski', $this->rodUPoruci());
    }

    // Mali obrazac sa dugmetom nosi ista polja kao glavni (u telu zahteva, ne u adresi), da dugme piše isti tekst.
    #[Test]
    public function link_nosi_upisana_polja_i_csrf_a_ne_stavlja_nista_u_adresu(): void
    {
        $html = $this->napisi($this->sablon(), ['ime' => 'Ana Anić', 'iskustvo' => "Prvi red.\nDrugi red \"pod navodnicima\"."])->assertOk()->getContent();

        preg_match('#<form class="rod-forma" method="post" action="([^"]+)" data-pomocnik-sablon-forma>(.*?)</form>#s', $html, $forma);

        $this->assertSame(route('pomocnik.napisi', 'pismo'), $forma[1]);
        $this->assertStringContainsString('name="_token"', $forma[2]);
        $this->assertStringContainsString('<input type="hidden" name="polje[ime]" value="Ana Anić">', $forma[2]);
        $this->assertStringContainsString('<input type="hidden" name="polje[iskustvo]" value="Prvi red.'."\n".'Drugi red &quot;pod navodnicima&quot;.">', $forma[2]);
        $this->assertStringNotContainsString('?', $forma[1]);
        $this->assertStringNotContainsString('Anić', $forma[1]);
    }

    /** @return array<string, array{0: string}> */
    public static function nejasnaImena(): array
    {
        return ['Saša' => ['Saša Petrović'], 'Vanja' => ['Vanja'], 'nepoznato ime' => ['Kiki'], 'prazno ime' => [''], 'ono što nije ime' => ['<b>12</b>']];
    }

    #[Test]
    #[DataProvider('nejasnaImena')]
    public function kad_rod_ne_moze_iz_imena_pomocnik_pita_i_ne_zove_model(string $ime): void
    {
        $html = $this->napisi($this->sablon(), ['ime' => $ime, 'iskustvo' => 'Radim u školi.'])->assertOk()->getContent();

        $this->assertTrue($this->jePitanje($html));
        Http::assertNothingSent();
        $this->assertSame('', $this->napisan($html), 'dok se ne odgovori, nema napisanog teksta');
        // Dva dugmeta, svako nosi rod; upisano ostaje u poljima i u malom obrascu.
        $this->assertMatchesRegularExpression('#<button class="dugme-rod" type="submit" name="rod" value="muski">radio sam</button>\s*<button class="dugme-rod" type="submit" name="rod" value="zenski">radila sam</button>#', $html);
        $this->assertStringContainsString('name="polje[iskustvo]" value="Radim u školi."', $html);
        $this->assertStringContainsString('>Radim u školi.</textarea>', $html);
        $this->assertNull($this->linkZaPromenu($html));
    }

    #[Test]
    public function odgovor_na_pitanje_pise_tekst_u_tom_rodu_bez_ponovnog_pitanja(): void
    {
        $sablon = $this->sablon();

        foreach (['muski', 'zenski'] as $rod) {
            $html = $this->napisi($sablon, ['ime' => 'Saša Petrović', 'iskustvo' => 'Radim u školi.'], ['rod' => $rod])->assertOk()->getContent();

            $this->assertFalse($this->jePitanje($html), $rod);
            $this->assertSame($rod, $this->rodUPoruci(), $rod);
            $this->assertSame('Napisan tekst. Saša Petrović', $this->napisan($html));
            $this->assertSame(($rod === 'muski' ? 'zenski|Promeni u ženski rod' : 'muski|Promeni u muški rod'), $this->linkZaPromenu($html));
        }
    }

    // Raniji odgovor stoji u obrascu, pa se pri sledećem pisanju ne pita opet; ime koje se prepoznaje ipak pobeđuje raniji izbor.
    #[Test]
    public function raniji_izbor_vazi_samo_kad_ime_ne_kaze_rod(): void
    {
        $sablon = $this->sablon();

        $html = $this->napisi($sablon, ['ime' => 'Saša Petrović', 'iskustvo' => 'x'], ['rod' => 'zenski'])->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="izabran_rod" value="zenski">'), 'glavni obrazac i mali obrazac');

        $html = $this->napisi($sablon, ['ime' => 'Saša Petrović', 'iskustvo' => 'x'], ['izabran_rod' => 'zenski'])->assertOk()->getContent();
        $this->assertFalse($this->jePitanje($html));
        $this->assertSame('zenski', $this->rodUPoruci());

        $this->napisi($sablon, ['ime' => 'Marko Anić', 'iskustvo' => 'x'], ['izabran_rod' => 'zenski'])->assertOk();
        $this->assertSame('muski', $this->rodUPoruci());

        // Ogledalo: bez ranijeg izbora isto ime se pita.
        $this->assertTrue($this->jePitanje($this->napisi($sablon, ['ime' => 'Saša Petrović', 'iskustvo' => 'x'])->getContent()));
    }

    #[Test]
    public function izabrano_dugme_pobedjuje_ime_a_izmisljena_vrednost_ne_znaci_nista(): void
    {
        $sablon = $this->sablon();

        foreach (['kosmati', '', ['niz'], 'MUSKI'] as $izmisljeno) {
            $html = $this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => 'x'], ['rod' => $izmisljeno])->assertOk()->getContent();

            $this->assertSame('zenski', $this->rodUPoruci(), print_r($izmisljeno, true));
            $this->assertFalse($this->jePitanje($html));
        }

        $this->assertTrue($this->jePitanje($this->napisi($sablon, ['ime' => 'Saša', 'iskustvo' => 'x'], ['rod' => 'kosmati', 'izabran_rod' => ['niz']])->getContent()));
    }

    // Šablon bez polja „ime" ne zna rod i ne pita: radi kao i pre.
    #[Test]
    public function sablon_bez_polja_ime_ne_pita_i_ne_nudi_rod(): void
    {
        $sablon = $this->sablon(['polja' => [['kljuc' => 'iskustvo', 'oznaka' => 'Iskustvo', 'objasnjenje' => null, 'dugo' => true]], 'tekst' => 'Poštovani, {iskustvo}']);

        $html = $this->napisi($sablon, ['iskustvo' => 'Radim.'])->assertOk()->getContent();

        $this->assertSame('Napisan tekst.', $this->napisan($html));
        $this->assertNull($this->rodUPoruci());
        $this->assertNull($this->linkZaPromenu($html));
        $this->assertFalse($this->jePitanje($html));
        // Ogledalo: dugme roda ipak može da stigne i tada se piše u tom rodu.
        $this->napisi($sablon, ['iskustvo' => 'Radim.'], ['rod' => 'zenski'])->assertOk();
        $this->assertSame('zenski', $this->rodUPoruci());
    }

    #[Test]
    public function pitanje_i_link_stizu_i_u_plutajuci_prozor(): void
    {
        $sablon = $this->sablon();

        $pitanje = $this->napisi($sablon, ['ime' => 'Saša', 'iskustvo' => 'x'], prozor: true)->assertOk()->getContent();
        $this->assertTrue($this->jePitanje($pitanje));
        $this->assertStringNotContainsString('<html', $pitanje);

        $napisan = $this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => 'x'], prozor: true)->assertOk()->getContent();
        $this->assertSame('muski|Promeni u muški rod', $this->linkZaPromenu($napisan));
        $this->assertStringNotContainsString('<html', $napisan);
    }

    // Kad model ne radi, sklopljen šablon uzima oblik iz izabranog roda; bez roda ostaju oba oblika.
    #[Test]
    public function sklopljen_sablon_postuje_rod_kad_model_ne_radi(): void
    {
        $this->modelRadi = false;
        $sablon = $this->sablonSaRodom();

        $zenski = $this->napisan($this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => 'Radila sam u školi.'])->assertOk()->getContent());
        $muski = $this->napisan($this->napisi($sablon, ['ime' => 'Marko Anić', 'iskustvo' => 'Radio sam u školi.'])->assertOk()->getContent());

        $this->assertSame("Poštovani,\n\nOglas sam videla na sajtu. Tamo sam radila kao konobar.\n\nRadila sam u školi.\n\nS poštovanjem,\nAna Anić", $zenski);
        $this->assertSame("Poštovani,\n\nOglas sam video na sajtu. Tamo sam radio kao konobar.\n\nRadio sam u školi.\n\nS poštovanjem,\nMarko Anić", $muski);
        // Odgovor na pitanje za ime koje ne kaže rod.
        $this->assertStringContainsString('Oglas sam video na sajtu', $this->napisan($this->napisi($sablon, ['ime' => 'Saša', 'iskustvo' => ''], ['rod' => 'muski'])->getContent()));
        $this->assertStringContainsString('Oglas sam videla na sajtu', $this->napisan($this->napisi($sablon, ['ime' => 'Saša', 'iskustvo' => ''], ['rod' => 'zenski'])->getContent()));
    }

    // Link ima smisla i kad ne piše model, ali samo ako šablon ima mesta sa dva oblika; inače ponovno pisanje ne bi ništa promenilo.
    #[Test]
    public function link_uz_sklopljen_sablon_stoji_samo_kad_sablon_ima_oblike_za_rod(): void
    {
        $this->modelRadi = false;

        $sa = $this->napisi($this->sablonSaRodom(), ['ime' => 'Ana Anić', 'iskustvo' => 'x'])->assertOk()->getContent();
        $bez = $this->napisi($this->sablon(['slug' => 'bez-roda']), ['ime' => 'Ana Anić', 'iskustvo' => 'x'])->assertOk()->getContent();

        $this->assertSame('muski|Promeni u muški rod', $this->linkZaPromenu($sa));
        $this->assertNull($this->linkZaPromenu($bez));
        // Ogledalo: ista strana kad model radi ima link i bez oblika u šablonu.
        $this->modelRadi = true;
        $this->assertSame('muski|Promeni u muški rod', $this->linkZaPromenu($this->napisi($this->sablon(['slug' => 'bez-roda-2']), ['ime' => 'Ana Anić', 'iskustvo' => 'x'])->getContent()));
    }

    // Šablon sa oblicima, ali bez polja „ime": rod nije poznat, pa ostaju oba oblika; izabrano dugme bira jedan.
    #[Test]
    public function bez_roda_u_sklopljenom_sablonu_ostaju_oba_oblika(): void
    {
        $this->modelRadi = false;
        $sablon = $this->sablon(['polja' => [['kljuc' => 'iskustvo', 'oznaka' => 'Iskustvo', 'objasnjenje' => null, 'dugo' => true]], 'tekst' => 'Oglas sam {video/videla}. {iskustvo}']);

        $this->assertSame('Oglas sam video/videla. Radim.', $this->napisan($this->napisi($sablon, ['iskustvo' => 'Radim.'])->assertOk()->getContent()));
        $this->assertSame('Oglas sam videla. Radim.', $this->napisan($this->napisi($sablon, ['iskustvo' => 'Radim.'], ['rod' => 'zenski'])->getContent()));
    }

    // Dugmad su dovoljno velika za prst (najmanje 44 piksela); link se od teksta razlikuje bojom i debljinom slova, bez crte (paket 66), a na prelaz mišem menja boju.
    #[Test]
    public function dugmad_roda_imaju_stil_za_prst_a_link_se_vidi_bojom_i_debljinom_bez_crte(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.rod-forma \{\s*display: flex;\s*flex-wrap: wrap;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-rod \{[^}]*min-height: 44px;[^}]*background: var\(--button-dark-bg\);[^}]*color: var\(--button-dark-text\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.veza-dugme \{[^}]*min-height: 44px;[^}]*background: none;[^}]*color: var\(--akcent\);[^}]*font-weight: 600;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.veza-dugme:hover \{\s*color: var\(--noc\);/', $stil);
        preg_match('/\n\.veza-dugme \{([^}]*)\}/', $stil, $veza);
        $this->assertNotEmpty($veza);
        $this->assertStringNotContainsString('underline', $veza[1]);
    }

    // Model prvo dobija šablon u izabranom rodu, pa ni on ne vidi zagrade sa dva oblika.
    #[Test]
    public function model_dobija_sablon_u_izabranom_rodu_a_upisano_ostaje_kako_je_upisano(): void
    {
        $sablon = $this->sablonSaRodom();

        $this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => '{video/videla} je upisano'])->assertOk();

        Http::assertSent(function (Request $zahtev) {
            $poruka = LazniClaude::poruka($zahtev);

            return str_contains($poruka, 'Oglas sam videla na sajtu. Tamo sam radila kao konobar.')
                && ! str_contains($poruka, "Šablon:\nPoštovani,\n\nOglas sam {video")
                && str_contains($poruka, '{iskustvo} (Iskustvo): {video/videla} je upisano');
        });

        $this->modelRadi = false;
        $this->assertStringContainsString("\n{video/videla} je upisano\n", $this->napisan($this->napisi($sablon, ['ime' => 'Ana Anić', 'iskustvo' => '{video/videla} je upisano'])->getContent()));
    }

    // Pitanje je Ivanov tekst (11.10.2026.), ne konstanta koja se poredi sama sa sobom.
    #[Test]
    public function tekstovi_roda_su_kakve_je_odredio_ivan(): void
    {
        $this->assertSame(self::PITANJE, PrikazPomocnika::ROD_PITANJE);
        $this->assertSame('Promeni u ženski rod', PrikazPomocnika::PROMENI_ROD['zenski']);
        $this->assertSame('Promeni u muški rod', PrikazPomocnika::PROMENI_ROD['muski']);
        $this->assertSame(['muski' => 'radio sam', 'zenski' => 'radila sam'], PrikazPomocnika::ROD_IZBOR);
    }

    // Pitanje i link se ne štampaju: na papiru ostaje samo tekst.
    #[Test]
    public function link_za_promenu_roda_je_u_delu_koji_se_ne_stampa(): void
    {
        $html = $this->napisi($this->sablon(), ['ime' => 'Ana Anić', 'iskustvo' => 'x'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<div class="uputstvo">\s*<form class="rod-forma"#', $html);
    }
}
