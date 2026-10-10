<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Support\PrikazSablona;
use App\Support\SklapanjeSablona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 51: javna strana „Šabloni": spisak kartica, obrazac sa poljima i sklopljen tekst. Ništa što čovek upiše se ne čuva.
#[Group('baza')]
class SabloniJavnoTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function sklopljen(string $html): string
    {
        preg_match('#<div class="sklopljen-tekst">(.*?)</div>#s', $html, $nalaz);

        return html_entity_decode($nalaz[1] ?? '');
    }

    #[Test]
    public function spisak_pokazuje_samo_objavljene_sablone_kao_kartice(): void
    {
        $this->sablon(['naslov' => 'Objavljen šablon']);
        Sablon::factory()->create(['naslov' => 'Nacrt šablon']);

        $html = $this->get(route('sabloni.index'))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Objavljen šablon', $tekst);
        $this->assertStringContainsString('Za dopis u kojem nekoga moliš.', $tekst);
        $this->assertStringNotContainsString('Nacrt šablon', $tekst);
        $this->assertSame(1, substr_count($html, '<article class="kartica redosled">'));
        $this->assertStringNotContainsString('<table', $html);
    }

    #[Test]
    public function prazan_spisak_kaze_da_nema_sablona(): void
    {
        Sablon::factory()->create();

        $this->assertStringContainsString(PrikazSablona::NEMA_SABLONA, $this->vidljivTekst($this->get(route('sabloni.index'))->assertOk()->getContent()));
    }

    #[Test]
    public function strana_nosi_obrazac_sa_poljima_natpisima_objasnjenjima_i_danasnjim_datumom(): void
    {
        $sablon = $this->sablon();

        $html = $this->get(route('sabloni.show', $sablon->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<form class="obrazac" method="post" action="'.route('sabloni.sklopi', $sablon->slug).'">', $html);
        $this->assertStringContainsString('name="_token"', $html);
        // Polje u jednom redu je input, a „više redova" je textarea; svako ima natpis vezan za njega.
        $this->assertMatchesRegularExpression('#<label for="polje-ime">Ime i prezime</label>#', $html);
        $this->assertMatchesRegularExpression('#<input id="polje-ime" type="text" name="ime" maxlength="255" value=""[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#<textarea id="polje-tekst" name="tekst" rows="5" maxlength="'.SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA.'"[^>]*>\s*</textarea>#', $html);
        $this->assertStringContainsString('Tvoje ime i prezime.', $html);
        // Datum je unapred upisan današnji; ostala polja su prazna. Polje bez objašnjenja ne crta red objašnjenja.
        $this->assertMatchesRegularExpression('#<input id="polje-datum" type="text" name="datum" maxlength="255" value="10\.10\.2026\."#', $html);
        $this->assertStringNotContainsString('id="objasnjenje-datum"', $html);
        $this->assertStringNotContainsString('class="sklopljen"', $html);
        $this->assertStringContainsString('<button type="submit">'.PrikazSablona::DUGME.'</button>', $html);
    }

    #[Test]
    public function popunjen_obrazac_vraca_sklopljen_tekst_i_pamti_upisano_u_poljima_samo_u_odgovoru(): void
    {
        $sablon = $this->sablon();

        $odgovor = $this->post(route('sabloni.sklopi', $sablon->slug), ['ime' => 'Ana Anić', 'tekst' => "Molim za pomoć.\nHvala.", 'datum' => '11.10.2026.'])->assertOk();
        $html = $odgovor->getContent();

        $this->assertSame("Ana Anić\n\nMolim: Molim za pomoć.\nHvala.\n\n11.10.2026.", $this->sklopljen($html));
        $this->assertStringContainsString('value="Ana Anić"', $html);
        $this->assertStringContainsString('<h2 id="tvoj-tekst">'.PrikazSablona::NASLOV_TEKSTA.'</h2>', $html);
        // Sklopljen tekst je iznad obrasca, da ga čovek na telefonu vidi bez skrolovanja.
        $this->assertLessThan(strpos($html, '<form class="obrazac"'), strpos($html, 'class="sklopljen-tekst"'));
    }

    // Prazno polje ne ostavlja „{ime}" u tekstu na strani (ogledalo: popunjeno ostaje).
    #[Test]
    public function prazno_polje_na_strani_daje_liniju_a_ne_viticaste_zagrade(): void
    {
        $sablon = $this->sablon();

        $tekst = $this->sklopljen($this->post(route('sabloni.sklopi', $sablon->slug), ['ime' => '', 'tekst' => 'Samo ovo.'])->assertOk()->getContent());

        $this->assertSame(SklapanjeSablona::PRAZNO."\n\nMolim: Samo ovo.\n\n".SklapanjeSablona::PRAZNO, $tekst);
        $this->assertStringNotContainsString('{', $tekst);
    }

    #[Test]
    public function upisan_html_se_ispisuje_kao_tekst(): void
    {
        $sablon = $this->sablon();

        $html = $this->post(route('sabloni.sklopi', $sablon->slug), ['ime' => '<script>alert(1)</script>', 'tekst' => '<b>podebljano</b> & "navodnici"'])->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame("<script>alert(1)</script>\n\nMolim: <b>podebljano</b> & \"navodnici\"\n\n".SklapanjeSablona::PRAZNO, $this->sklopljen($html));
    }

    // Ništa se ne čuva: posle sklapanja ni jedna tabela nema ni jedan red više ni izmenjen red šablona.
    #[Test]
    public function sklapanje_nista_ne_upisuje_u_bazu(): void
    {
        $sablon = $this->sablon();
        $stanje = fn () => collect(array_column(array_filter(Schema::getTables(), fn (array $tabela) => ($tabela['schema'] ?? DB::getDatabaseName()) === DB::getDatabaseName()), 'name'))->mapWithKeys(fn (string $tabela) => [$tabela => DB::table($tabela)->count()])->all();
        $pre = $stanje();
        $izmenjen = $sablon->fresh()->updated_at;

        $this->travelTo(now()->addHour());
        $this->post(route('sabloni.sklopi', $sablon->slug), ['ime' => 'Lični podatak', 'tekst' => 'Još jedan lični podatak.'])->assertOk();

        $this->assertNotEmpty($pre, 'merilo mora da obiđe tabele');
        $this->assertSame($pre, $stanje());
        $this->assertTrue($izmenjen->equalTo($sablon->fresh()->updated_at));
        $this->assertSame(1, $pre['sabloni']);
    }

    // Nacrt je za posetioca nepostojeći (i strana i sklapanje), a objavljen je dostupan: ogledalo.
    #[Test]
    public function nacrt_nema_ni_stranu_ni_sklapanje(): void
    {
        $nacrt = Sablon::factory()->create(['naslov' => 'Tajni nacrt']);
        $objavljen = $this->sablon();

        foreach ([$this->get(route('sabloni.show', $nacrt->slug)), $this->post(route('sabloni.sklopi', $nacrt->slug), ['ime' => 'A'])] as $odgovor) {
            $odgovor->assertNotFound()->assertSee(PrikazSablona::NEMA_STRANE)->assertDontSee('Tajni nacrt');
        }

        $this->get(route('sabloni.show', $objavljen->slug))->assertOk();
        $this->post(route('sabloni.sklopi', $objavljen->slug), [])->assertOk();
        $this->get('/sabloni/nema-takvog')->assertNotFound();
    }

    // Polje čiji ključ nije u šablonu se ignoriše: čovek ne može da ubaci mesto u tekst.
    #[Test]
    public function nepoznato_polje_u_zahtevu_se_ignorise(): void
    {
        $sablon = $this->sablon();

        $tekst = $this->sklopljen($this->post(route('sabloni.sklopi', $sablon->slug), ['ime' => 'Ana', 'tekst' => 'T', 'datum' => 'D', 'tuđe' => 'ubačeno', 'slug' => 'x'])->assertOk()->getContent());

        $this->assertStringNotContainsString('ubačeno', $tekst);
        $this->assertSame("Ana\n\nMolim: T\n\nD", $tekst);
    }

    #[Test]
    public function meni_ima_sabloni_i_sklopljen_tekst_se_vidi_kao_dopis(): void
    {
        $html = $this->get(route('sabloni.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('sabloni.index'), '#').'">'.PrikazSablona::NASLOV.'</a>#', $html);
        $this->assertMatchesRegularExpression('/\.sklopljen-tekst,\n\.primer-tekst \{[^}]*white-space: pre-wrap;/', $this->stilBezKomentara());
    }
}
