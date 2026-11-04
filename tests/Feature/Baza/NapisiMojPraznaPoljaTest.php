<?php

namespace Tests\Feature\Baza;

use App\Enums\Rod;
use App\Enums\StatusObjave;
use App\Filament\Resources\Sabloni\Pages\CreateSablon;
use App\Models\Sablon;
use App\Models\User;
use App\Support\PrikazPomocnika;
use App\Support\SklapanjeSablona;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 70/T10 (i T9): prazna polja u „Napiši moj" i u adminu: podrazumevana reč i izostavljen red uz polje, i kad piše model, i kad ide sklopljen šablon.
#[Group('baza')]
class NapisiMojPraznaPoljaTest extends AdminBazaTestCase
{
    private const MODEL = LazniClaude::ADRESA;

    private string $odgovorModela = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        Http::fake([self::MODEL => fn () => LazniClaude::odgovor($this->odgovorModela)]);
    }

    private function sablon(): Sablon
    {
        return Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [
                ['kljuc' => 'ime', 'oznaka' => 'Ime i prezime', 'objasnjenje' => null, 'dugo' => false],
                ['kljuc' => 'kome', 'oznaka' => 'Kome se obraćaš?', 'objasnjenje' => null, 'dugo' => false, 'ako_prazno' => 'Poštovani'],
                ['kljuc' => 'skola', 'oznaka' => 'Koju školu imaš?', 'objasnjenje' => null, 'dugo' => false, 'opciono' => true],
                ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true],
            ],
            'tekst' => "{kome},\n\nprijavljujem se za stipendiju.\nMoja škola: {skola}\n{tekst}\n\nS poštovanjem,\n{ime}",
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

    private function porukaModelu(): string
    {
        return (string) Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::MODEL)->first()[0]->data()['messages'][0]['content'];
    }

    // Model dobija šablon bez reda sa praznom školom i podrazumevanu reč kao upisano; ono što napiše prolazi.
    #[Test]
    public function model_dobija_sablon_bez_praznog_opcionog_reda_i_podrazumevanu_rec_kao_upisano(): void
    {
        $this->odgovorModela = "Poštovani,\n\nprijavljujem se za stipendiju.\nRoditelji ne mogu da plate knjige.\n\nS poštovanjem,\nAna Marković";

        $html = $this->napisi($this->sablon(), ['ime' => 'Ana Marković', 'kome' => '', 'skola' => '', 'tekst' => 'Roditelji ne mogu da plate knjige.']);
        $poruka = $this->porukaModelu();

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        $this->assertSame($this->odgovorModela, $this->napisan($html));
        $this->assertStringNotContainsString('Moja škola', $poruka);
        $this->assertStringNotContainsString('{skola}', $poruka);
        $this->assertStringContainsString('{kome} (Kome se obraćaš?): Poštovani', $poruka);
        $this->assertStringContainsString("Šablon:\n{kome},\n\nprijavljujem se za stipendiju.\n{tekst}", $poruka);
        // Ogledalo: upisana škola ostaje u šablonu i među upisanim.
        $this->odgovorModela = 'Poštovani, ...';
        $this->napisi($this->sablon(), ['ime' => 'Ana Marković', 'kome' => '', 'skola' => 'Ekonomski fakultet u Nišu', 'tekst' => 'Tekst.']);
        $this->assertStringContainsString('Moja škola: {skola}', (string) Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::MODEL)->last()[0]->data()['messages'][0]['content']);
    }

    // Podrazumevana reč je među onim što model sme da ponovi (broj ili e-pošta u njoj nisu „kojih nema u odgovorima").
    #[Test]
    public function model_sme_da_ponovi_podrazumevanu_rec_sa_brojem(): void
    {
        $sablon = Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'telefon', 'oznaka' => 'Telefon', 'objasnjenje' => null, 'dugo' => false, 'ako_prazno' => 'Pozovite 011 123 456']],
            'tekst' => "{ime}\n{telefon}",
        ]);
        $this->odgovorModela = "Ana Anić\nPozovite 011 123 456";

        $html = $this->napisi($sablon, ['ime' => 'Ana Anić', 'telefon' => '']);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_NAPISAN, $html);
        $this->assertSame($this->odgovorModela, $this->napisan($html));
        // Ogledalo: broj kog nema ni u upisanom ni u podrazumevanoj reči ne prolazi.
        $this->odgovorModela = "Ana Anić\nPozovite 011 123 457";
        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $this->napisi($sablon, ['ime' => 'Ana Anić', 'telefon' => '']));
    }

    // T9 i T10 zajedno: model koji počne imenom autora kad je „kome" prazno dobija odbijen tekst, a sklopljen šablon počinje sa „Poštovani,".
    #[Test]
    public function tekst_modela_koji_pocinje_imenom_ide_sklopljen_sa_postovani(): void
    {
        $this->odgovorModela = "Ana Marković,\n\nprijavljujem se za stipendiju.\nRoditelji ne mogu da plate knjige.\n\nS poštovanjem,\nAna Marković";
        $sablon = $this->sablon();
        $polja = ['ime' => 'Ana Marković', 'kome' => '', 'skola' => '', 'tekst' => 'Roditelji ne mogu da plate knjige.'];

        $html = $this->napisi($sablon, $polja);

        $this->assertStringContainsString(PrikazPomocnika::NAPOMENA_SLOZEN, $html);
        $this->assertSame("Poštovani,\n\nprijavljujem se za stipendiju.\nRoditelji ne mogu da plate knjige.\n\nS poštovanjem,\nAna Marković", $this->napisan($html));
        $this->assertSame(SklapanjeSablona::sklopi($sablon, $polja, Rod::Zenski), $this->napisan($html));
    }

    // Admin: pravila uz polje se snimaju u podatke šablona; oba odjednom ne prolaze.
    #[Test]
    public function admin_zadaje_podrazumevanu_rec_i_izostavljen_red_uz_polje(): void
    {
        $polja = [
            ['kljuc' => 'kome', 'oznaka' => 'Kome', 'dugo' => false, 'ako_prazno' => 'Poštovani', 'opciono' => false],
            ['kljuc' => 'skola', 'oznaka' => 'Škola', 'dugo' => false, 'ako_prazno' => '', 'opciono' => true],
            ['kljuc' => 'ime', 'oznaka' => 'Ime', 'dugo' => false],
        ];

        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.', 'polja' => $polja, 'tekst' => "{kome},\n{skola}\n{ime}"])
            ->call('create')
            ->assertHasNoFormErrors();

        $sablon = Sablon::query()->firstOrFail();
        $this->assertSame('Poštovani', $sablon->polja[0]['ako_prazno']);
        $this->assertTrue($sablon->polja[1]['opciono']);
        $this->assertSame("Poštovani,\nAna", SklapanjeSablona::sklopi($sablon, ['ime' => 'Ana']));

        // Ogledalo: i reč i izostavljanje na istom polju je greška.
        Livewire::test(CreateSablon::class)
            ->fillForm(['naslov' => 'Drugo', 'objasnjenje' => 'B', 'polja' => [['kljuc' => 'kome', 'oznaka' => 'Kome', 'dugo' => false, 'ako_prazno' => 'Poštovani', 'opciono' => true]], 'tekst' => '{kome}'])
            ->call('create')
            ->assertHasFormErrors(['polja.0.ako_prazno']);

        $this->assertSame(1, Sablon::query()->count());
        $this->assertSame(StatusObjave::Nacrt, $sablon->status);
    }

    #[Test]
    public function polje_u_adminu_nosi_oznaku_i_objasnjenje_za_nova_pravila(): void
    {

        $stranica = Livewire::test(CreateSablon::class)->fillForm(['polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'dugo' => false]]]);

        $stranica->assertSee('Ako ostane prazno, piše')
            ->assertSee('Reč koja stoji umesto praznog polja, npr. „Poštovani“. Bez nje i bez izostavljanja reda, prazno polje je linija za olovku.')
            ->assertSee('Ako ostane prazno, red se ne vidi')
            ->assertSee('Uključi za polje koje nije obavezno: red sa njim, a i odeljak koji bez njega ostane bez ijednog polja, ne piše u tekstu.');
    }
}
