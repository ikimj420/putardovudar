<?php

namespace Tests\Feature\Baza;

use App\Models\Vodic;
use App\Support\PrikazVodica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 56 T1 i 64 T1: vodič ima primere (naslov + tekst); na strani stoje jedan ispod drugog, odmah ispod koraka, svaki sa svojim dugmetom „Kopiraj".
// Vodič bez primera nema taj deo.
#[Group('baza')]
class VodicPrimerTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    private function strana(Vodic $vodic): string
    {
        return $this->get(route('vodici.show', $vodic->slug))->assertOk()->getContent();
    }

    #[Test]
    public function primer_stoji_ispod_koraka_sa_dugmetom_kopiraj_koje_je_skriveno_bez_skripta(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.', 'Drugi korak.'], 'primeri' => [['naslov' => 'Za stipendiju', 'tekst' => "Poštovani,\n\nPrimer pisma."]]]);

        $html = $this->strana($vodic);

        $this->assertStringContainsString('<h2 class="naslov-izvora" id="primer-naslov-1">Za stipendiju</h2>', $html);
        $this->assertStringContainsString('<div class="primer-tekst" id="primer-tekst-1">'."Poštovani,\n\nPrimer pisma.".'</div>', $html);
        // Redosled: tekst, koraci, primer.
        $this->assertLessThan(strpos($html, 'Za stipendiju'), strpos($html, '<ol class="koraci">'));
        $this->assertLessThan(strpos($html, 'class="primer-tekst"'), strpos($html, 'Drugi korak.'));
        // Dugme je u kodu, ali skriveno dok skript ne radi (kopiranje u međuspremnik traži skript); opisano je naslovom svog primera.
        $this->assertStringContainsString('<button class="dugme-kopiraj" type="button" data-kopiraj="primer-tekst-1" data-kopirano="'.PrikazVodica::KOPIRANO.'" aria-describedby="primer-naslov-1" aria-live="polite" hidden>'.PrikazVodica::KOPIRAJ.'</button>', $html);
    }

    // Više primera: jedan ispod drugog, redom kojim su upisani, svaki sa svojim naslovom, tekstom i dugmetom; ni jedan id se ne ponavlja.
    #[Test]
    public function vise_primera_se_vidi_jedan_ispod_drugog_redom_svaki_sa_svojim_kopiraj(): void
    {
        $naslovi = ['Za stipendiju', 'Za praksu', 'Za posao', 'Za program ili obuku'];
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.'], 'primeri' => array_map(fn (string $naslov, int $i) => ['naslov' => $naslov, 'tekst' => 'Tekst za '.$i.'.'], $naslovi, array_keys($naslovi))]);

        $html = $this->strana($vodic);

        preg_match_all('#<h2 class="naslov-izvora" id="primer-naslov-(\d+)">([^<]+)</h2>\s*<div class="primer-tekst" id="primer-tekst-\1">([^<]+)</div>\s*<button class="dugme-kopiraj" type="button" data-kopiraj="primer-tekst-\1"[^>]*aria-describedby="primer-naslov-\1"#', $html, $nalazi);

        $this->assertSame(['1', '2', '3', '4'], $nalazi[1]);
        $this->assertSame($naslovi, $nalazi[2]);
        $this->assertSame(['Tekst za 0.', 'Tekst za 1.', 'Tekst za 2.', 'Tekst za 3.'], $nalazi[3]);
        $this->assertSame(4, substr_count($html, 'class="dugme-kopiraj"'));
        // Svi primeri su posle koraka.
        $this->assertLessThan(strpos($html, 'primer-naslov-1'), strpos($html, '<ol class="koraci">'));
        // Nijedan id na strani se ne ponavlja.
        preg_match_all('/\bid="([^"]+)"/', $html, $idovi);
        $this->assertSame([], array_keys(array_filter(array_count_values($idovi[1]), fn (int $broj) => $broj > 1)));
    }

    /** @return array<string, array{0: mixed}> */
    public static function bezPrimera(): array
    {
        return [
            'nije postavljen' => [null],
            'prazan spisak' => [[]],
            'prazan tekst' => [[['naslov' => 'Prazan', 'tekst' => '']]],
            'samo razmaci' => [[['naslov' => 'Razmaci', 'tekst' => "  \n\t "]]],
            'bez teksta' => [[['naslov' => 'Bez teksta']]],
            'nije spisak primera' => [['tekst nije u spisku']],
            'stavka nije primer' => [[5, null, 'tekst']],
        ];
    }

    // Ogledalo: vodič bez primera nema ni naslov primera, ni polje, ni dugme; koraci i tekst su tu.
    #[Test]
    #[DataProvider('bezPrimera')]
    public function vodic_bez_primera_nema_taj_deo(mixed $primeri): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.']]);
        $vodic->forceFill(['primeri' => $primeri])->save();

        $html = $this->strana($vodic);

        $this->assertStringContainsString('<ol class="koraci">', $html);
        $this->assertStringNotContainsString('primer-tekst', $html);
        $this->assertStringNotContainsString('primer-naslov', $html);
        $this->assertStringNotContainsString('dugme-kopiraj', $html);
    }

    // Primer bez teksta se preskače, a ostali se vide i dalje redom i sa brojevima bez rupa; naslov kog nema postaje „Primer".
    #[Test]
    public function prazan_primer_se_preskace_a_naslov_koga_nema_postaje_primer(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['primeri' => [['naslov' => 'Prvi', 'tekst' => ''], ['tekst' => 'Drugi tekst.'], ['naslov' => '  ', 'tekst' => 'Treći tekst.'], ['naslov' => 'Četvrti', 'tekst' => 'Četvrti tekst.']]]);

        $html = $this->strana($vodic);

        preg_match_all('#<h2 class="naslov-izvora" id="primer-naslov-(\d+)">([^<]+)</h2>\s*<div class="primer-tekst" id="primer-tekst-\d+">([^<]+)</div>#', $html, $nalazi);

        $this->assertSame(['1', '2', '3'], $nalazi[1]);
        $this->assertSame([PrikazVodica::PRIMER, PrikazVodica::PRIMER, 'Četvrti'], $nalazi[2]);
        $this->assertSame(['Drugi tekst.', 'Treći tekst.', 'Četvrti tekst.'], $nalazi[3]);
        $this->assertStringNotContainsString('>Prvi<', $html);
    }

    // Vodič sa primerom, a bez koraka: primer je tu i nema praznog dela za korake.
    #[Test]
    public function primer_postoji_i_bez_koraka(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => null, 'primeri' => [['naslov' => 'Primer', 'tekst' => 'Samo primer.']]]);

        $html = $this->strana($vodic);

        $this->assertStringNotContainsString('<ol class="koraci">', $html);
        $this->assertStringContainsString('Samo primer.', $this->vidljivTekst($html));
    }

    #[Test]
    public function primer_i_naslov_se_ispisuju_kao_tekst_a_ne_kao_html(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['primeri' => [['naslov' => '<b>Naslov</b>', 'tekst' => '<script>alert(1)</script> Ana & "Marko"']]]);

        $html = $this->strana($vodic);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Naslov</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Ana &amp; &quot;Marko&quot;', $html);
        $this->assertStringContainsString('&lt;b&gt;Naslov&lt;/b&gt;', $html);
    }

    // Nacrt je i dalje nepostojeći, pa ni primeri ne cure.
    #[Test]
    public function primer_nacrta_se_ne_vidi(): void
    {
        $vodic = Vodic::factory()->create(['primeri' => [['naslov' => 'Tajni naslov', 'tekst' => 'Tajni primer.']]]);

        $this->get(route('vodici.show', $vodic->slug))->assertNotFound()->assertDontSee('Tajni primer.')->assertDontSee('Tajni naslov');
    }

    // Prvi primer je uzor oblika za pomoćnika, a prazni i nepotpuni se preskaču.
    #[Test]
    public function prvi_primer_je_prvi_koji_ima_tekst(): void
    {
        $this->assertNull(Vodic::factory()->make(['primeri' => null])->prviPrimer());
        $this->assertNull(Vodic::factory()->make(['primeri' => [['naslov' => 'A', 'tekst' => ' ']]])->prviPrimer());
        $this->assertSame('Drugi.', Vodic::factory()->make(['primeri' => [['naslov' => 'A', 'tekst' => ''], ['naslov' => 'B', 'tekst' => 'Drugi.'], ['naslov' => 'C', 'tekst' => 'Treći.']]])->prviPrimer());
    }

    // Skript kopira tekst iz elementa na koji pokazuje dugme; bez međuspremnika tekst se označi; dugme se prikazuje tek kad skript radi.
    #[Test]
    public function skript_kopira_primer_i_otkriva_dugme_tek_kad_radi(): void
    {
        $skript = (string) file_get_contents(public_path('js/javno.js'));

        foreach ([
            "querySelectorAll('[data-kopiraj]')", 'dugme.hidden = false;', "dogadjaj.target.closest('[data-kopiraj]')", 'navigator.clipboard.writeText(izvor.textContent)',
            "document.execCommand('copy')", 'opseg.selectNodeContents(izvor);', "dugme.getAttribute('data-kopirano')",
        ] as $trag) {
            $this->assertStringContainsString($trag, $skript, $trag);
        }
    }

    // Stil: dugme je veliko bar 44 px, skriveno se ne crta (inače bi se videlo i bez skripta), primer čuva novi red, a u štampi nema dugmeta.
    #[Test]
    public function stil_cuva_novi_red_u_primeru_i_gasi_skriveno_dugme(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.dugme-kopiraj \{[^}]*min-height: 44px;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-kopiraj\[hidden\] \{\s*display: none;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.sklopljen-tekst,\n\.primer-tekst \{[^}]*white-space: pre-wrap;/', $stil);
        $this->assertStringContainsString('.dugme-kopiraj {', substr($stil, (int) strpos($stil, '@media print')));
    }
}
