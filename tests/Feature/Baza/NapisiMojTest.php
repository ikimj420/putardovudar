<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Models\Vodic;
use App\Support\PrikazPomocnika;
use App\Support\SklapanjeSablona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;
use Tests\Podrska\LazniClaude;

// Paket 56 T3: „Napiši moj". Pitanja šablona, tekst koji piše model samo iz upisanog, provera koda i sklopljen šablon kad provera ne prođe.
// Ništa što čovek upiše se ne čuva.
#[Group('baza')]
class NapisiMojTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const MODEL = LazniClaude::ADRESA;

    private const UNOSI = ['ime' => 'Ana Anić', 'tekst' => "Molim za pomoć.\nHvala.", 'datum' => '11.10.2026.'];

    private string|\Closure|null $odgovorModela = 'x';

    private bool $lazniModel = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function sablon(array $dopuna = []): Sablon
    {
        return Sablon::factory()->objavljen()->create([...[
            'naslov' => 'Molba', 'objasnjenje' => 'Za dopis u kojem nekoga moliš.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => 'Tvoje ime i prezime.', 'dugo' => false],
                ['kljuc' => 'tekst', 'oznaka' => 'Šta moliš', 'objasnjenje' => 'Svojim rečima.', 'dugo' => true],
                ['kljuc' => 'datum', 'oznaka' => 'Datum', 'objasnjenje' => null, 'dugo' => false],
            ],
            'tekst' => "{ime}\n\nMolim: {tekst}\n\n{datum}",
        ], ...$dopuna]);
    }

    // Http::fake ostaje na prvom postavljenom odgovoru, pa se postavlja jednom, a odgovor se menja kroz svojstvo.
    private function model(string|callable|null $tekst): void
    {
        $this->odgovorModela = $tekst;

        if ($this->lazniModel) {
            return;
        }

        $this->lazniModel = true;
        Http::fake([self::MODEL => function () {
            $tekst = $this->odgovorModela;

            return match (true) {
                $tekst === null => throw new ConnectionException('nema veze'),
                is_callable($tekst) => $tekst(),
                default => LazniClaude::odgovor($tekst),
            };
        }]);
    }

    /**
     * @param  array<string, mixed>|null  $unosi
     * @param  array<string, mixed>  $dopuna  ostalo što stiže uz polja (izabran rod)
     */
    private function napisi(Sablon $sablon, ?array $unosi = self::UNOSI, bool $prozor = false, array $dopuna = []): TestResponse
    {
        $zahtev = $prozor ? $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']) : $this;

        return $zahtev->post(route('pomocnik.napisi', $sablon->slug), ($unosi === null ? [] : ['polje' => $unosi]) + $dopuna);
    }

    private function napisan(string $html): string
    {
        preg_match('#<div class="sklopljen-tekst" id="napisan-tekst">(.*?)</div>#s', $html, $nalaz);

        return html_entity_decode($nalaz[1] ?? '');
    }

    #[Test]
    public function strana_nosi_pitanja_sablona_sa_natpisima_objasnjenjima_i_danasnjim_datumom(): void
    {
        $sablon = $this->sablon();

        $html = $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('<h1>Molba</h1>', $html);
        $this->assertStringContainsString('Za dopis u kojem nekoga moliš.', $tekst);
        $this->assertStringContainsString('<form class="obrazac" method="post" action="'.route('pomocnik.napisi', $sablon->slug).'" data-pomocnik-sablon-forma>', $html);
        $this->assertStringContainsString('name="_token"', $html);
        // Polje u jednom redu je input, „više redova" je textarea; svako ima natpis vezan za njega.
        $this->assertMatchesRegularExpression('#<label for="polje-ime">Ime i prezime</label>#', $html);
        $this->assertMatchesRegularExpression('#<input id="polje-ime" type="text" name="polje\[ime\]" maxlength="255" value=""[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#<textarea id="polje-tekst" name="polje\[tekst\]" rows="5" maxlength="'.SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA.'"[^>]*>\s*</textarea>#', $html);
        $this->assertStringContainsString('Tvoje ime i prezime.', $html);
        // Datum je unapred upisan današnji; polje bez objašnjenja ne crta red objašnjenja.
        $this->assertMatchesRegularExpression('#<input id="polje-datum" type="text" name="polje\[datum\]" maxlength="255" value="10\.10\.2026\."#', $html);
        $this->assertStringNotContainsString('id="objasnjenje-datum"', $html);
        $this->assertStringContainsString('<button type="submit">'.PrikazPomocnika::NAPISI.'</button>', $html);
        $this->assertStringNotContainsString('id="napisan-tekst"', $html);
        // Bez skripta je to obična strana pomoćnika: nema plutajućeg prozora, a meni vodi na stranu pomoćnika.
        $this->assertStringNotContainsString('id="pomocnik-prozor"', $html);
        Http::assertNothingSent();
    }

    // Ogledalo: šablon bez polja „datum" nema unapred upisan datum.
    #[Test]
    public function sablon_bez_polja_datum_nema_unapred_upisan_datum(): void
    {
        $sablon = $this->sablon(['polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false]], 'tekst' => '{ime}']);

        $this->assertStringNotContainsString('10.10.2026.', $this->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent());
    }

    #[Test]
    public function prozor_dobija_ista_pitanja_bez_okvira_strane(): void
    {
        $sablon = $this->sablon();
        // Ogledalo: obična strana ima okvir.
        $this->assertStringContainsString('<html', $this->get(route('pomocnik.napisi.forma', $sablon->slug))->getContent());

        $prozor = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('pomocnik.napisi.forma', $sablon->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('<html', $prozor);
        $this->assertStringNotContainsString('<header', $prozor);
        $this->assertStringContainsString('<h2 class="plutajuci-sablon">Molba</h2>', $prozor);
        $this->assertStringContainsString('<form class="obrazac" method="post" action="'.route('pomocnik.napisi', $sablon->slug).'" data-pomocnik-sablon-forma>', $prozor);
        $this->assertStringContainsString('name="polje[ime]"', $prozor);
    }

    // Nacrt je za posetioca nepostojeći (i pitanja i pisanje), a objavljen je dostupan: ogledalo.
    #[Test]
    public function nacrt_i_nepostojeci_sablon_nemaju_ni_pitanja_ni_pisanje(): void
    {
        $this->model('x');
        $nacrt = Sablon::factory()->create(['naslov' => 'Tajni nacrt']);
        $objavljen = $this->sablon();

        foreach ([$this->get(route('pomocnik.napisi.forma', $nacrt->slug)), $this->napisi($nacrt), $this->get('/pomocnik/napisi/nema-takvog'), $this->napisi($nacrt, self::UNOSI, true)] as $odgovor) {
            $odgovor->assertNotFound()->assertSee(PrikazPomocnika::NEMA_SABLONA_STRANE)->assertDontSee('Tajni nacrt');
        }
        Http::assertNothingSent();

        $this->get(route('pomocnik.napisi.forma', $objavljen->slug))->assertOk();
        $this->napisi($objavljen)->assertOk();
    }

    #[Test]
    public function tekst_modela_koji_prolazi_proveru_se_prikazuje_i_ide_jedan_poziv(): void
    {
        $sablon = $this->sablon();
        $this->model("[IME]\n\nMolim vas za pomoć. Hvala.\n\n11.10.2026.");

        $html = $this->napisi($sablon)->assertOk()->getContent();

        $this->assertSame("Ana Anić\n\nMolim vas za pomoć. Hvala.\n\n11.10.2026.", $this->napisan($html));
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        $this->assertStringNotContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertStringContainsString('data-kopiraj="napisan-tekst" data-kopirano="Kopirano" aria-live="polite" hidden>Kopiraj</button>', $html);
        // Pitanja ostaju ispod teksta sa upisanim vrednostima, da se tekst prepravi.
        $this->assertStringContainsString('value="Ana Anić"', $html);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zahtev) {
            $poruka = LazniClaude::poruka($zahtev);

            return ! array_key_exists('temperature', $zahtev->data())
                && ! LazniClaude::jeJson($zahtev)
                && str_contains(LazniClaude::uputstvo($zahtev), 'Koristi samo ono što je čovek upisao')
                && str_contains($poruka, "Šablon:\n{ime}\n\nMolim: {tekst}\n\n{datum}")
                && str_contains($poruka, "Upisano:\n{ime} (Ime i prezime): [IME]\n{tekst} (Šta moliš): Molim za pomoć.\nHvala.\n{datum} (Datum): 11.10.2026.")
                && ! str_contains($poruka, 'Ana Anić');
        });
    }

    // Paket 67 (Ivan, 11.10.2026.): primer vodiča se modelu ne šalje, jer je prepisivao rečenice koje čovek nije upisao. Primer ostaje na strani vodiča, za ljude.
    // Poruka ima samo šablon, upisano i rod, a uputstvo ne pominje primer; ni nacrt vodiča ni vodič sa drugim šablonom ne menjaju ništa.
    #[Test]
    public function modelu_ne_ide_nijedan_primer_vodica_a_poruka_ima_samo_sablon_i_popunjena_polja(): void
    {
        $sablon = $this->sablon();
        Vodic::factory()->objavljen()->create(['sablon_id' => $sablon->getKey(), 'primeri' => [['naslov' => 'Prazan', 'tekst' => ' '], ['naslov' => 'Za stipendiju', 'tekst' => 'Prvi primer sa tekstom.'], ['naslov' => 'Za praksu', 'tekst' => 'Drugi primer.']]]);
        Vodic::factory()->create(['sablon_id' => $sablon->getKey(), 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Primer iz nacrta.']]]);
        Vodic::factory()->objavljen()->create(['sablon_id' => $this->sablon(['naslov' => 'Zahtev'])->getKey(), 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Primer drugog šablona.']]]);
        $this->model('x');

        $this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => '', 'datum' => ''])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zahtev) {
            $uputstvo = LazniClaude::uputstvo($zahtev);
            $poruka = LazniClaude::poruka($zahtev);

            return ! str_contains($poruka, 'Primer') && ! str_contains($poruka, 'primer')
                && ! str_contains($uputstvo, 'primer') && ! str_contains($uputstvo, 'Primer')
                && str_starts_with($poruka, "Šablon:\n{ime}\n\nMolim: {tekst}\n\n{datum}")
                && str_ends_with($poruka, "Upisano:\n{ime} (Ime i prezime): [IME]");
        });
    }

    // Ogledalo: primer jeste na strani vodiča, za ljude; merilo ne bi prošlo kad bi primeri bili prazni.
    #[Test]
    public function primer_ostaje_na_strani_vodica_a_model_ga_ne_dobija(): void
    {
        $sablon = $this->sablon();
        $vodic = Vodic::factory()->objavljen()->create(['sablon_id' => $sablon->getKey(), 'primeri' => [['naslov' => 'Za stipendiju', 'tekst' => 'Prvi primer sa tekstom.']]]);
        $this->model('x');

        $this->assertStringContainsString('Prvi primer sa tekstom.', $this->get(route('vodici.show', $vodic->slug))->assertOk()->getContent());
        $this->napisi($sablon)->assertOk();

        Http::assertSent(fn (Request $zahtev) => ! str_contains(json_encode($zahtev->data(), JSON_UNESCAPED_UNICODE), 'Prvi primer sa tekstom.'));
    }

    /** @return array<string, array{0: string}> */
    public static function izmisljeniOdgovori(): array
    {
        return [
            'izmišljena škola, datum i telefon' => ['Ana Anić je završila Osnovnu školu „Sveti Sava“ 12.6.2015. Zovite na 064/765-4321.'],
            'samo izmišljen datum' => ['Ana Anić, 5. maja 2020.'],
            'samo izmišljen telefon' => ['Ana Anić, tel. 065 765 4321.'],
            'izmišljena e-pošta' => ['Ana Anić, ana@izmisljeno.rs'],
            'izmišljena veza' => ['Ana Anić, https://izmisljeno.rs/ana'],
        ];
    }

    #[Test]
    #[DataProvider('izmisljeniOdgovori')]
    public function izmisljen_podatak_u_odgovoru_modela_menja_sklopljen_sablon(string $odgovorModela): void
    {
        $sablon = $this->sablon();
        $this->model($odgovorModela);

        $html = $this->napisi($sablon)->assertOk()->getContent();

        $this->assertSame("Ana Anić\n\nMolim: Molim za pomoć.\nHvala.\n\n11.10.2026.", $this->napisan($html));
        $this->assertStringNotContainsString($odgovorModela, html_entity_decode($html));
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertStringNotContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        // Ni sklopljen šablon ne dobija ništa osim upisanog.
        $this->assertSame(1, substr_count($html, 'id="napisan-tekst"'));
    }

    // Ogledalo: isti odgovor, ali sa podacima koje je čovek upisao, prolazi.
    #[Test]
    public function isti_oblik_odgovora_sa_upisanim_podacima_prolazi(): void
    {
        $sablon = $this->sablon();
        $this->model('Ana Anić, tel. 065 765 4321, ana@primer.rs, https://primer.rs/ana, 5. maja 2020.');

        $html = $this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Tel. 065 765 4321, ana@primer.rs, https://primer.rs/ana, 5. maja 2020.', 'datum' => ''])->assertOk()->getContent();

        $this->assertSame('Ana Anić, tel. 065 765 4321, ana@primer.rs, https://primer.rs/ana, 5. maja 2020.', $this->napisan($html));
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
    }

    // Model sme da ponovi stalni tekst šablona (i broj u njemu), ali ne ono što stoji samo u primeru vodiča (koji mu se i ne šalje) ni u polju kog šablon nema.
    #[Test]
    public function dozvoljeno_je_upisano_i_stalni_tekst_sablona_a_ne_primer_ni_tude_polje(): void
    {
        $sablon = $this->sablon(['tekst' => "{ime}\n\nOdgovorite u roku od 15 dana.\n\n{tekst}", 'polja' => [
            ['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false],
            ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true],
        ]]);
        Vodic::factory()->objavljen()->create(['sablon_id' => $sablon->getKey(), 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Marko Marković, tel. 011 555 666.']]]);

        $this->model("Ana Anić\n\nOdgovorite u roku od 15 dana.\n\nTražim pomoć.");
        $this->assertSame("Ana Anić\n\nOdgovorite u roku od 15 dana.\n\nTražim pomoć.", $this->napisan($this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Tražim pomoć.'])->getContent()), 'stalni tekst i broj iz njega');

        $this->model("Ana Anić\n\nOdgovorite u roku od 15 dana.\n\nTražim pomoć. Tel. 011 555 666.");
        $this->assertSame("Ana Anić\n\nOdgovorite u roku od 15 dana.\n\nTražim pomoć.", $this->napisan($this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Tražim pomoć.'])->getContent()), 'telefon samo iz primera');

        $this->model("Ana Anić\n\nOdgovorite u roku od 15 dana.\n\nTražim pomoć. Tel. 064 999 888.");
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Tražim pomoć.', 'tuđe' => 'zovi 064 999 888'])->getContent(), 'telefon iz tuđeg polja');
        // Ogledalo: isti telefon u polju koje šablon ima prolazi.
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Tražim pomoć. Tel. 064 999 888.'])->getContent());
    }

    // Model ne radi (nema veze ili greška servera): isti sklopljen šablon, i jedan poziv, bez ponavljanja.
    #[Test]
    public function kad_model_ne_radi_stoji_sklopljen_sablon(): void
    {
        $sablon = $this->sablon();

        foreach ([null, fn () => Http::response('', 500)] as $kvar) {
            $this->model($kvar);

            $html = $this->napisi($sablon)->assertOk()->getContent();

            $this->assertSame("Ana Anić\n\nMolim: Molim za pomoć.\nHvala.\n\n11.10.2026.", $this->napisan($html));
            $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
            $this->assertStringNotContainsString(PrikazPomocnika::NE_RADI, $this->vidljivTekst($html));
        }
    }

    // Prazno polje u sklopljenom šablonu daje liniju za olovku, a ne viticaste zagrade.
    #[Test]
    public function prazno_polje_u_sklopljenom_sablonu_daje_liniju(): void
    {
        $sablon = $this->sablon();
        $this->model(null);

        $tekst = $this->napisan($this->napisi($sablon, ['ime' => '', 'tekst' => 'Samo ovo.'], dopuna: ['rod' => 'muski'])->assertOk()->getContent());

        $this->assertSame(SklapanjeSablona::PRAZNO."\n\nMolim: Samo ovo.\n\n".SklapanjeSablona::PRAZNO, $tekst);
        $this->assertStringNotContainsString('{', $tekst);
    }

    // Ništa se ne čuva: posle pisanja ni jedna tabela nema ni jedan red više, šablon nije izmenjen, a upisano nije ni u sesiji.
    #[Test]
    public function pisanje_nista_ne_upisuje(): void
    {
        $sablon = $this->sablon();
        $this->model('Ana Anić, lični podatak.');
        $stanje = fn () => collect(array_column(array_filter(Schema::getTables(), fn (array $tabela) => ($tabela['schema'] ?? DB::getDatabaseName()) === DB::getDatabaseName()), 'name'))->mapWithKeys(fn (string $tabela) => [$tabela => DB::table($tabela)->count()])->all();
        $pre = $stanje();
        $izmenjen = $sablon->fresh()->updated_at;

        $this->travelTo(now()->addHour());
        $odgovor = $this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Lični podatak.', 'datum' => '']);
        $odgovor->assertOk();

        $this->assertNotEmpty($pre, 'merilo mora da obiđe tabele');
        $this->assertSame(1, $pre['sabloni']);
        $this->assertSame($pre, $stanje());
        $this->assertTrue($izmenjen->equalTo($sablon->fresh()->updated_at));
        $this->assertStringNotContainsString('Lični podatak', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Lični podatak', $odgovor->getContent(), 'ogledalo: upisano jeste u odgovoru');
    }

    // Polje čiji ključ nije u šablonu se ignoriše; polje koje nije niz i prazan zahtev daju prazna polja; upisano se ispisuje kao tekst.
    #[Test]
    public function nepoznato_polje_se_ignorise_a_upisan_html_ispisuje_kao_tekst(): void
    {
        $sablon = $this->sablon();
        $this->model(null);

        $tekst = $this->napisan($this->napisi($sablon, ['ime' => 'Ana', 'tekst' => 'T', 'datum' => 'D', 'tuđe' => 'ubačeno'])->assertOk()->getContent());
        $this->assertSame("Ana\n\nMolim: T\n\nD", $tekst);

        foreach ([null, ['ime' => ['niz']]] as $nevazece) {
            $this->assertSame(SklapanjeSablona::PRAZNO."\n\nMolim: ".SklapanjeSablona::PRAZNO."\n\n".SklapanjeSablona::PRAZNO, $this->napisan($this->napisi($sablon, $nevazece, dopuna: ['rod' => 'muski'])->assertOk()->getContent()));
        }

        $html = $this->napisi($sablon, ['ime' => '<script>alert(1)</script>', 'tekst' => '<b>podebljano</b> & "navodnici"'], dopuna: ['rod' => 'muski'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame("<script>alert(1)</script>\n\nMolim: <b>podebljano</b> & \"navodnici\"\n\n".SklapanjeSablona::PRAZNO, $this->napisan($html));
    }

    // Polje se kraći na granicu i pre nego što ode modelu; do granice stoji celo.
    #[Test]
    public function polje_se_kraci_na_granicu(): void
    {
        $sablon = $this->sablon(['polja' => [['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]], 'tekst' => '{tekst}']);
        $this->model('x');
        $granica = SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA;

        foreach ([$granica + 50, $granica] as $duzina) {
            $this->napisi($sablon, ['tekst' => str_repeat('a', $duzina)])->assertOk();

            Http::assertSent(fn (Request $zahtev) => str_ends_with(LazniClaude::poruka($zahtev), "Upisano:\n{tekst} (Tekst): ".str_repeat('a', $granica)));
        }

        $this->model(null);
        $this->assertSame(str_repeat('a', $granica), $this->napisan($this->napisi($sablon, ['tekst' => str_repeat('a', $granica + 50)])->getContent()), 'sklopljen tekst je isti');
    }

    // Isto ograničenje kao za pitanja: broji se zajedno (isti ključ), preko granice stiže poruka sa upisanim, a model se ne zove.
    #[Test]
    public function ogranicenje_je_isto_kao_za_pitanja_i_broji_se_zajedno(): void
    {
        $sablon = $this->sablon();
        $this->model('x');
        foreach (range(1, 3) as $broj) {
            $this->napisi($sablon)->assertOk();
        }
        foreach (range(1, 2) as $broj) {
            $this->post(route('pomocnik.pitaj'), ['pitanje' => '   '])->assertStatus(422);
        }
        $this->assertCount(3, Http::recorded(), 'prazno pitanje ne zove model, ali se broji');

        $strana = $this->napisi($sablon, ['ime' => 'Ana Anić', 'tekst' => 'Još nešto.', 'datum' => ''])->assertStatus(429)->getContent();
        $this->assertStringContainsString('<p class="greska" role="alert">'.PrikazPomocnika::PREVISE_PITANJA.'</p>', $strana);
        $this->assertStringContainsString('value="Ana Anić"', $strana);
        $this->assertStringContainsString('<html', $strana);

        $prozor = $this->napisi($sablon, self::UNOSI, true)->assertStatus(429)->getContent();
        $this->assertStringContainsString('<p class="greska" role="alert">'.PrikazPomocnika::PREVISE_PITANJA.'</p>', $prozor);
        $this->assertStringContainsString('<h2 class="plutajuci-sablon">Molba</h2>', $prozor);
        $this->assertStringNotContainsString('<html', $prozor);
        $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertStatus(429);
        $this->assertCount(3, Http::recorded());
    }

    // Ogledalo: do granice sve prolazi, a druga adresa nije ograničena zbog prve.
    #[Test]
    public function ogranicenje_je_po_adresi(): void
    {
        $sablon = $this->sablon();
        $this->model('x');

        foreach (range(1, 5) as $broj) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->napisi($sablon)->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->napisi($sablon)->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->napisi($sablon)->assertOk();
    }

    #[Test]
    public function dugme_napisi_moj_je_ispod_primera_samo_kad_vodic_ima_objavljen_sablon(): void
    {
        $sablon = $this->sablon();
        $sNacrtom = Sablon::factory()->create();
        $sa = Vodic::factory()->objavljen()->create(['sablon_id' => $sablon->getKey(), 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Primer pisma.']]]);
        $bezPrimera = Vodic::factory()->objavljen()->create(['sablon_id' => $sablon->getKey(), 'primeri' => []]);
        $nacrtSablona = Vodic::factory()->objavljen()->create(['sablon_id' => $sNacrtom->getKey(), 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Primer.']]]);
        $bez = Vodic::factory()->objavljen()->create(['sablon_id' => null, 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Primer.']]]);

        $dugme = '<a class="dugme-veza" href="'.route('pomocnik.napisi.forma', $sablon->slug).'" data-pomocnik-sablon>'.PrikazPomocnika::NAPISI_MOJ.'</a>';
        $html = $this->get(route('vodici.show', $sa->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, $dugme));
        $this->assertStringContainsString('<div class="dugme-red">', $html);
        $this->assertGreaterThan(strpos($html, 'id="primer-tekst"'), strpos($html, $dugme), 'dugme je ispod primera');
        // Dugme ne zavisi od primera.
        $this->assertStringContainsString($dugme, $this->get(route('vodici.show', $bezPrimera->slug))->getContent());

        foreach ([$nacrtSablona, $bez] as $vodic) {
            $this->assertStringNotContainsString(PrikazPomocnika::NAPISI_MOJ, $this->get(route('vodici.show', $vodic->slug))->assertOk()->getContent());
        }
    }

    // Dugme je na strani vodiča veza ka strani sa pitanjima (radi bez skripta), a skript je otvara u prozoru pomoćnika.
    #[Test]
    public function skript_otvara_pitanja_u_prozoru_i_salje_ih_istom_putanjom(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        foreach ([
            "document.addEventListener('click'", "dogadjaj.target.closest('[data-pomocnik-sablon]')", 'zatrazi(veza.href)', "dogadjaj.target.closest('[data-pomocnik-sablon-forma]')",
            "'X-Requested-With': 'XMLHttpRequest'", 'new FormData(obrazac)', 'window.location.href = veza.href;', 'obrazac.submit();', 'prikaziKopiraj(obrazac);', 'prikaziKopiraj(blok);', 'input:not([type="hidden"]), textarea',
            'napraviPoruku(\'poruka-pomocnik poruka-obrazac\')', "obrazac.closest('.poruka-obrazac')", "element.scrollIntoView({ block: 'start' });", 'pomeriNa(blok);', 'odgovor.status === 404',
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
        $this->assertDoesNotMatchRegularExpression('#https?://#', $skript);
    }
}
