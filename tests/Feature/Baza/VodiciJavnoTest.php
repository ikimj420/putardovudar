<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Vodic;
use App\Support\PrikazVodica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class VodiciJavnoTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    #[Test]
    public function spisak_pokazuje_samo_objavljene_vodice_kao_kartice(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Objavljen vodič', 'kratak_opis' => 'Kratak opis objavljenog.']);
        Vodic::factory()->create(['naslov' => 'Nacrt vodič']);

        $html = $this->get(route('vodici.index'))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Objavljen vodič', $tekst);
        $this->assertStringContainsString('Kratak opis objavljenog.', $tekst);
        $this->assertStringNotContainsString('Nacrt vodič', $tekst);
        $this->assertSame(1, substr_count($html, '<article class="kartica">'));
        $this->assertStringNotContainsString('<table', $html);
    }

    #[Test]
    public function prazan_spisak_kaze_da_nema_vodica(): void
    {
        Vodic::factory()->create();

        $tekst = $this->vidljivTekst($this->get(route('vodici.index'))->assertOk()->getContent());

        $this->assertStringContainsString(PrikazVodica::NEMA_VODICA, $tekst);
    }

    #[Test]
    public function spisak_je_po_abecedi_naslova(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Zadnji']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Prvi']);

        $tekst = $this->vidljivTekst($this->get(route('vodici.index'))->getContent());

        $this->assertLessThan(mb_strpos($tekst, 'Zadnji'), mb_strpos($tekst, 'Prvi'));
    }

    #[Test]
    public function objavljen_vodic_ima_stranu_sa_tekstom_i_koracima_ali_bez_beleske(): void
    {
        $vodic = Vodic::factory()->objavljen()->create([
            'naslov' => 'Kako napisati CV', 'kratak_opis' => 'Kratko o CV-u.', 'tekst' => "Prvi red.\nDrugi red.",
            'koraci' => ['Napiši kontakt.', 'Dodaj školu.'], 'beleska' => 'Tajna beleška za admina.',
        ]);

        $html = $this->get(route('vodici.show', $vodic->slug))->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Kako napisati CV', $tekst);
        $this->assertStringContainsString('Kratko o CV-u.', $tekst);
        $this->assertStringContainsString('Prvi red.', $tekst);
        $this->assertStringContainsString("Prvi red.<br />\nDrugi red.", $html);
        $this->assertStringContainsString('<ol class="koraci">', $html);
        $this->assertStringContainsString('<li>Napiši kontakt.</li><li>Dodaj školu.</li>', preg_replace('#>\s+<#', '><', $html) ?? '');
        $this->assertStringNotContainsString('Tajna beleška', $html);
        $this->assertSame('Kako napisati CV - Putardo Vudar', $this->naslovStrane($html));
    }

    // Ogledalo: vodič bez koraka ne crta naslov „Koraci".
    #[Test]
    public function vodic_bez_koraka_nema_odeljak_koraci(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['koraci' => null]);

        $html = $this->get(route('vodici.show', $vodic->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString(PrikazVodica::KORACI, $this->vidljivTekst($html));
        $this->assertStringNotContainsString('<ol', $html);
    }

    #[Test]
    public function nacrt_vodic_je_za_posetioca_nepostojeci_a_posle_objave_se_vidi(): void
    {
        $vodic = Vodic::factory()->create(['naslov' => 'Još nije gotov']);

        $this->get(route('vodici.show', $vodic->slug))->assertNotFound();
        $this->get(route('vodici.show', 'nepostojeci-slug'))->assertNotFound();

        $vodic->update(['status' => StatusObjave::Objavljeno]);

        $this->get(route('vodici.show', $vodic->slug))->assertOk();
    }

    #[Test]
    public function tekst_se_ispisuje_bez_html_a(): void
    {
        $vodic = Vodic::factory()->objavljen()->create(['tekst' => '<script>alert(1)</script> Običan tekst.', 'koraci' => ['<b>korak</b>']]);

        $html = $this->get(route('vodici.show', $vodic->slug))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>korak</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;korak&lt;/b&gt;', $html);
    }

    #[Test]
    public function jedino_mesto_za_javno_je_opseg_javni(): void
    {
        Vodic::factory()->objavljen()->count(2)->create();
        Vodic::factory()->count(3)->create();

        $this->assertSame(2, Vodic::javni()->count());
        $this->assertSame(5, Vodic::query()->count());
    }

    #[Test]
    public function slug_se_pravi_iz_naslova_a_isti_naslov_dobija_broj(): void
    {
        $prvi = Vodic::factory()->create(['naslov' => 'Kako napisati CV', 'slug' => '']);
        $drugi = Vodic::factory()->create(['naslov' => 'Kako napisati CV', 'slug' => '']);

        $this->assertSame('kako-napisati-cv', $prvi->slug);
        $this->assertSame('kako-napisati-cv-2', $drugi->slug);
    }

    #[Test]
    public function novi_vodic_je_nacrt_a_koraci_su_niz(): void
    {
        $vodic = Vodic::query()->create(['naslov' => 'Novi', 'kratak_opis' => 'Opis.', 'tekst' => 'Tekst.', 'koraci' => ['A', 'B']]);

        $this->assertSame(StatusObjave::Nacrt, $vodic->fresh()->status);
        $this->assertSame(['A', 'B'], $vodic->fresh()->koraci);
        $this->assertSame(0, Vodic::javni()->count());
    }
}
