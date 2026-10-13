<?php

namespace Tests\Feature\Baza;

use App\Enums\Rod;
use App\Models\Sablon;
use App\Services\Pomocnik\ProveraNapisanogTeksta;
use App\Support\PrikazPomocnika;
use App\Support\SklapanjeSablona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 70/T9: kad je „kome" prazno, tekst modela koji počinje imenom autora („Ana Marković,") nije pozdrav i ne prolazi proveru; ide sklopljen šablon.
#[Group('baza')]
class NapisiMojBezPozdravaImenomTest extends BazaTestCase
{
    use RefreshDatabase;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    private string $odgovorModela = '';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([self::OLLAMA => fn () => Http::response(['message' => ['content' => $this->odgovorModela]])]);
    }

    private function sablon(bool $saKome = true): Sablon
    {
        $polja = [['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false]];

        if ($saKome) {
            $polja[] = ['kljuc' => 'kome', 'oznaka' => 'Kome se obraćaš?', 'objasnjenje' => null, 'dugo' => false];
        }

        $polja[] = ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true];

        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.', 'polja' => $polja,
            'tekst' => $saKome ? "{kome},\n\nprijavljujem se za stipendiju. {tekst}\n\nS poštovanjem,\n{ime}" : "{ime}\n\nprijavljujem se za stipendiju. {tekst}",
        ]);
    }

    /** @param  array<string, string>  $polja */
    private function napisi(Sablon $sablon, array $polja): string
    {
        return $this->post(route('pomocnik.napisi', $sablon->slug), ['polje' => $polja, 'rod' => 'zenski'])->assertOk()->getContent();
    }

    private function napisan(string $html): string
    {
        preg_match('#<div class="sklopljen-tekst" id="napisan-tekst">(.*?)</div>#s', $html, $nalaz);

        return html_entity_decode($nalaz[1] ?? '');
    }

    /** @return array<string, array{0: string}> početak teksta modela koji je ime autora */
    public static function pocetakImenom(): array
    {
        return [
            'ime i zarez' => ["Ana Marković,\n\nprijavljujem se za stipendiju. Roditelji ne mogu da plate knjige."],
            'ime bez zareza' => ["Ana Marković\n\nprijavljujem se za stipendiju. Roditelji ne mogu da plate knjige."],
            'ime malim slovima i višak razmaka' => ["  ana   MARKOVIĆ,\n\nprijavljujem se za stipendiju. Roditelji ne mogu da plate knjige."],
            'ime pa tekst u istom redu' => ['Ana Marković, prijavljujem se za stipendiju. Roditelji ne mogu da plate knjige.'],
        ];
    }

    #[Test]
    #[DataProvider('pocetakImenom')]
    public function tekst_koji_pocinje_imenom_autora_kad_je_kome_prazno_ne_prolazi(string $odgovor): void
    {
        $this->odgovorModela = $odgovor;
        $sablon = $this->sablon();
        $polja = ['ime' => 'Ana Marković', 'kome' => '', 'tekst' => 'Roditelji ne mogu da plate knjige.'];

        $html = $this->napisi($sablon, $polja);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertSame(SklapanjeSablona::sklopi($sablon, $polja, Rod::Zenski), $this->napisan($html));
        $this->assertStringNotContainsString('Ana Marković,', substr($this->napisan($html), 0, 20));
    }

    // Ogledalo: pozdrav „Poštovani," i prazno „kome" prolaze, kao i ime autora kad „kome" nije prazno ili šablon nema to polje.
    #[Test]
    public function pozdrav_prolazi_a_ime_autora_prolazi_gde_nije_pozdrav(): void
    {
        $polja = ['ime' => 'Ana Marković', 'kome' => '', 'tekst' => 'Roditelji ne mogu da plate knjige.'];

        $this->odgovorModela = "Poštovani,\n\nprijavljujem se za stipendiju. Roditelji ne mogu da plate knjige.\n\nS poštovanjem,\nAna Marković";
        $html = $this->napisi($this->sablon(), $polja);
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        $this->assertSame($this->odgovorModela, $this->napisan($html));

        // „kome" je upisano, pa tekst koji počinje tim imenom prolazi.
        $this->odgovorModela = "Ana Marković,\n\nprijavljujem se za stipendiju. Roditelji ne mogu da plate knjige.\n\nS poštovanjem,\nAna Marković";
        $html = $this->napisi($this->sablon(), [...$polja, 'kome' => 'Ana Marković']);
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);

        // Šablon bez polja „kome" (na primer CV) počinje imenom i to je u redu.
        $this->odgovorModela = "Ana Marković\n\nprijavljujem se za stipendiju. Roditelji ne mogu da plate knjige.";
        $html = $this->napisi($this->sablon(saKome: false), ['ime' => 'Ana Marković', 'tekst' => 'Roditelji ne mogu da plate knjige.']);
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> tekst, ime, razlog */
    public static function proveraDirektno(): array
    {
        return [
            'počinje imenom' => ['Ana Marković, prijavljujem se.', 'Ana Marković', 'pozdrav imenom autora'],
            'ime u sredini' => ['Poštovani, ja sam Ana Marković.', 'Ana Marković', null],
            'ime na kraju' => ["Poštovani,\n\nS poštovanjem,\nAna Marković", 'Ana Marković', null],
            'ime je deo duže reči' => ['Ana Markovićeva je pisala.', 'Ana Marković', null],
            'samo prvo ime ne važi' => ['Ana je pisala. Ana Marković', 'Ana Marković', null],
            'prazno ime' => ['Poštovani, ...', '', null],
            'bez imena' => ['Ana Marković, ...', null, null],
        ];
    }

    #[Test]
    #[DataProvider('proveraDirektno')]
    public function provera_pocetka_teksta_direktno(string $tekst, ?string $ime, ?string $razlog): void
    {
        $this->assertSame($razlog, (new ProveraNapisanogTeksta)->razlogOdbijanja($tekst, $tekst, null, $ime));
    }
}
