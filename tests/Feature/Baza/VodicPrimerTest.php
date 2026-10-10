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

// Paket 56 T1: vodič ima polje „Primer"; na strani stoji odmah ispod koraka, sa dugmetom „Kopiraj". Vodič bez primera nema taj deo.
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
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.', 'Drugi korak.'], 'primer' => "Poštovani,\n\nPrimer pisma."]);

        $html = $this->strana($vodic);

        $this->assertStringContainsString('<h2 class="naslov-izvora">'.PrikazVodica::PRIMER.'</h2>', $html);
        $this->assertStringContainsString('<div class="primer-tekst" id="primer-tekst">'."Poštovani,\n\nPrimer pisma.".'</div>', $html);
        // Redosled: tekst, koraci, primer.
        $this->assertLessThan(strpos($html, PrikazVodica::PRIMER), strpos($html, '<ol class="koraci">'));
        $this->assertLessThan(strpos($html, 'class="primer-tekst"'), strpos($html, 'Drugi korak.'));
        // Dugme je u kodu, ali skriveno dok skript ne radi (kopiranje u međuspremnik traži skript).
        $this->assertStringContainsString('<button class="dugme-kopiraj" type="button" data-kopiraj="primer-tekst" data-kopirano="'.PrikazVodica::KOPIRANO.'" aria-live="polite" hidden>'.PrikazVodica::KOPIRAJ.'</button>', $html);
    }

    /** @return array<string, array{0: string|null}> */
    public static function bezPrimera(): array
    {
        return ['nije postavljen' => [null], 'prazan tekst' => [''], 'samo razmaci' => ["  \n\t "]];
    }

    // Ogledalo: vodič bez primera nema ni naslov „Primer", ni polje, ni dugme; koraci i tekst su tu.
    #[Test]
    #[DataProvider('bezPrimera')]
    public function vodic_bez_primera_nema_taj_deo(?string $primer): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => ['Prvi korak.'], 'primer' => $primer]);

        $html = $this->strana($vodic);

        $this->assertStringContainsString('<ol class="koraci">', $html);
        $this->assertStringNotContainsString('primer-tekst', $html);
        $this->assertStringNotContainsString('dugme-kopiraj', $html);
        $this->assertStringNotContainsString('>'.PrikazVodica::PRIMER.'<', $html);
    }

    // Vodič sa primerom, a bez koraka: primer je tu i nema praznog dela za korake.
    #[Test]
    public function primer_postoji_i_bez_koraka(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => null, 'primer' => 'Samo primer.']);

        $html = $this->strana($vodic);

        $this->assertStringNotContainsString('<ol class="koraci">', $html);
        $this->assertStringContainsString('Samo primer.', $this->vidljivTekst($html));
    }

    #[Test]
    public function primer_se_ispisuje_kao_tekst_a_ne_kao_html(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['primer' => '<script>alert(1)</script> Ana & "Marko"']);

        $html = $this->strana($vodic);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Ana &amp; &quot;Marko&quot;', $html);
    }

    // Nacrt je i dalje nepostojeći, pa ni primer ne curi.
    #[Test]
    public function primer_nacrta_se_ne_vidi(): void
    {
        $vodic = Vodic::factory()->create(['primer' => 'Tajni primer.']);

        $this->get(route('vodici.show', $vodic->slug))->assertNotFound()->assertDontSee('Tajni primer.');
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
