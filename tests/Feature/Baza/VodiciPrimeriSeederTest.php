<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Models\Sablon;
use App\Models\Vodic;
use App\Support\SklapanjeSablona;
use Database\Seeders\VodiciPrimeriSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 64/T1: seeder upisuje primere vodičima koji ih nemaju, dodaje šablone kao nacrt i vezuje vodič za šablon samo kad ga nema; ništa postojeće ne menja.
#[Group('baza')]
class VodiciPrimeriSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    private const VODICI = ['kako-napisati-prvi-cv', 'kako-napisati-motivaciono-pismo', 'kako-napisati-email-uz-prijavu'];

    /** @return array<string, Vodic> slug → vodič, svi bez primera i bez šablona */
    private function vodici(): array
    {
        $vodici = [];

        foreach (self::VODICI as $slug) {
            $vodici[$slug] = Vodic::factory()->create(['slug' => $slug, 'naslov' => 'Vodič '.$slug, 'koraci' => ['Prvi korak.'], 'primeri' => null]);
        }

        return $vodici;
    }

    private function pokreni(): void
    {
        (new VodiciPrimeriSeeder)->run();
    }

    #[Test]
    public function vodic_bez_primera_dobija_primere_redom_a_vodic_koji_ih_ima_ostaje_kakav_je(): void
    {
        $vodici = $this->vodici();
        $svoj = [['naslov' => 'Moj', 'tekst' => 'Primer koji je neko već upisao.']];
        $vodici['kako-napisati-email-uz-prijavu']->update(['primeri' => $svoj]);

        $this->pokreni();

        $this->assertSame(['Za stipendiju', 'Za praksu', 'Za posao', 'Za program ili obuku'], array_column($vodici['kako-napisati-motivaciono-pismo']->fresh()->primeri, 'naslov'));
        $this->assertSame(['Primer'], array_column($vodici['kako-napisati-prvi-cv']->fresh()->primeri, 'naslov'));
        $this->assertStringStartsWith('Ana Marković', $vodici['kako-napisati-prvi-cv']->fresh()->primeri[0]['tekst']);
        // Ogledalo: vodič koji već ima primer ga zadržava, ma šta seeder nudi.
        $this->assertSame($svoj, $vodici['kako-napisati-email-uz-prijavu']->fresh()->primeri);
    }

    // Seeder ne pravi vodiče (ni šablone za vodič kog nema) i ne menja ništa drugo na vodiču.
    #[Test]
    public function vodic_kog_nema_se_ne_pravi_a_ostala_polja_vodica_se_ne_menjaju(): void
    {
        $this->pokreni();

        $this->assertSame(0, Vodic::query()->count());

        $vodic = Vodic::factory()->objavljen()->create(['slug' => 'kako-napisati-prvi-cv', 'naslov' => 'Moj naslov', 'tekst' => 'Moj tekst.', 'koraci' => ['Korak.'], 'beleska' => 'Moja beleška.']);
        $pre = $vodic->fresh()->only(['naslov', 'slug', 'kratak_opis', 'tekst', 'koraci', 'beleska', 'status']);

        $this->pokreni();

        $this->assertSame($pre, $vodic->fresh()->only(['naslov', 'slug', 'kratak_opis', 'tekst', 'koraci', 'beleska', 'status']));
        $this->assertSame(1, Vodic::query()->count());
    }

    #[Test]
    public function sabloni_ulaze_kao_nacrt_a_sablon_sa_postojecim_slugom_se_ne_dira(): void
    {
        $svoj = Sablon::factory()->objavljen()->create(['slug' => 'cv', 'naslov' => 'Moj CV', 'objasnjenje' => 'Moje.', 'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => '', 'dugo' => false]], 'tekst' => '{ime}']);

        $this->pokreni();

        $this->assertEqualsCanonicalizing(['cv', 'email-uz-prijavu', 'motivaciono-pismo'], Sablon::query()->pluck('slug')->all());
        $this->assertSame('Moj CV', $svoj->fresh()->naslov);
        $this->assertSame('{ime}', $svoj->fresh()->tekst);
        $this->assertSame(StatusObjave::Objavljeno, $svoj->fresh()->status);
        foreach (Sablon::query()->whereIn('slug', ['email-uz-prijavu', 'motivaciono-pismo'])->get() as $sablon) {
            $this->assertSame(StatusObjave::Nacrt, $sablon->status, $sablon->slug);
        }
        // Samo nacrt: nijedan od novih šablona nije javan, pa „Napiši moj" ne postoji dok ga Ivan ne objavi.
        $this->assertSame(['cv'], Sablon::javni()->pluck('slug')->all());
    }

    // Vodič se vezuje za svoj šablon samo kad nema nijedan; vezan vodič ostaje vezan, i kad seeder nudi drugi.
    #[Test]
    public function vodic_se_vezuje_za_sablon_samo_kad_nema_nijedan(): void
    {
        $vodici = $this->vodici();
        $tudji = Sablon::factory()->create(['slug' => 'moj-sablon']);
        $vodici['kako-napisati-email-uz-prijavu']->update(['sablon_id' => $tudji->getKey()]);

        $this->pokreni();

        $this->assertSame(Sablon::query()->where('slug', 'cv')->value('id'), $vodici['kako-napisati-prvi-cv']->fresh()->sablon_id);
        $this->assertSame(Sablon::query()->where('slug', 'motivaciono-pismo')->value('id'), $vodici['kako-napisati-motivaciono-pismo']->fresh()->sablon_id);
        // Ogledalo: vodič koji je već imao šablon ga zadržava.
        $this->assertSame($tudji->getKey(), $vodici['kako-napisati-email-uz-prijavu']->fresh()->sablon_id);
    }

    #[Test]
    public function drugo_pokretanje_ne_menja_nista(): void
    {
        $this->vodici();
        $this->pokreni();
        $pre = [Vodic::query()->orderBy('id')->get()->map->getAttributes()->all(), Sablon::query()->orderBy('id')->get()->map->getAttributes()->all()];

        $this->pokreni();

        $this->assertSame($pre, [Vodic::query()->orderBy('id')->get()->map->getAttributes()->all(), Sablon::query()->orderBy('id')->get()->map->getAttributes()->all()]);
    }

    // Svako mesto za polje u tekstu ima svoje polje, svako polje ima mesto, a šablon može da se objavi (inače bi ostavio prazan red u tekstu).
    #[Test]
    public function sabloni_imaju_polja_za_svako_mesto_u_tekstu_i_mogu_da_se_objave(): void
    {
        $this->pokreni();
        $this->vodici();
        $this->pokreni();

        $this->assertCount(3, VodiciPrimeriSeeder::sabloni());
        foreach (Sablon::query()->get() as $sablon) {
            $kljucevi = $sablon->kljucevi();

            $this->assertNotSame([], $kljucevi, $sablon->slug);
            $this->assertEqualsCanonicalizing($kljucevi, SklapanjeSablona::kljuceUTekstu($sablon->tekst), $sablon->slug);
            $this->assertSame(array_values(array_unique($kljucevi)), $kljucevi, $sablon->slug);
            $this->assertNotSame('', trim($sablon->objasnjenje));
            foreach ($sablon->polja as $polje) {
                $this->assertNotSame('', trim($polje['oznaka']), $sablon->slug);
                $this->assertIsBool($polje['dugo']);
                $this->assertStringNotContainsString('**', $polje['objasnjenje'], 'ostatak oznake za podebljano iz dokumenta');
            }

            $sablon->update(['status' => StatusObjave::Objavljeno]);
            $this->assertSame(StatusObjave::Objavljeno, $sablon->fresh()->status);
        }
    }

    // Tekstovi su isti kao u Dokumentacija/VODICI-PRIMERI.md, samo za ono što je tamo označeno kao odobreno (✅). Dokumentacija je van gita, pa se bez nje test glasno preskače.
    #[Test]
    public function tekstovi_su_doslovno_isti_kao_u_dokumentu_i_samo_odobreni(): void
    {
        $putanja = base_path('Dokumentacija/VODICI-PRIMERI.md');

        if (! is_file($putanja)) {
            $this->markTestSkipped('Dokumentacija/VODICI-PRIMERI.md nema na ovom računaru (folder Dokumentacija je van gita), pa se tekstovi ne mogu uporediti.');
        }

        $dokument = (string) file_get_contents($putanja);
        $poredjeno = 0;

        foreach (VodiciPrimeriSeeder::primeri() as $slug => $primeri) {
            preg_match('/^### [^\n]*`'.preg_quote($slug, '/').'`[^\n]*$/m', $dokument, $zaglavlje, PREG_OFFSET_CAPTURE);
            $this->assertNotEmpty($zaglavlje, $slug);
            $odeljak = $this->odeljak($dokument, (int) $zaglavlje[0][1], $zaglavlje[0][0]);

            foreach ($primeri as $primer) {
                // Primer sa naslovom je pod „#### naslov", jedini primer vodiča je u bloku odmah ispod zaglavlja.
                $deo = $primer['naslov'] === 'Primer' ? $odeljak : (string) substr($odeljak, (int) strpos($odeljak, '#### '.$primer['naslov']));
                $zaglavljePrimera = $primer['naslov'] === 'Primer' ? $zaglavlje[0][0] : strtok($deo, "\n");

                $this->assertStringContainsString('✅', (string) $zaglavljePrimera, $slug.' / '.$primer['naslov'].' nije odobren u dokumentu');
                $this->assertSame($primer['tekst'], $this->prviBlok($deo), $slug.' / '.$primer['naslov']);
                $poredjeno++;
            }
        }

        foreach (VodiciPrimeriSeeder::sabloni() as $sablon) {
            preg_match('/^### [^\n]*`'.preg_quote($sablon['slug'], '/').'` →[^\n]*$/m', $dokument, $zaglavlje, PREG_OFFSET_CAPTURE);
            $this->assertNotEmpty($zaglavlje, $sablon['slug']);
            $odeljak = $this->odeljak($dokument, (int) $zaglavlje[0][1], $zaglavlje[0][0]);
            $this->assertStringContainsString('✅', $zaglavlje[0][0], $sablon['slug'].' nije odobren u dokumentu');

            $this->assertSame($sablon['tekst'], $this->prviBlok((string) substr($odeljak, (int) strpos($odeljak, 'Šablon kad Ollama ne radi'))), $sablon['slug']);

            preg_match_all('/^\d+\. `([a-z0-9_]+)` · (.*?)( \(dugo\))? · ?(.*)$/m', $odeljak, $polja, PREG_SET_ORDER);
            $this->assertSame(
                array_map(fn (array $polje) => [$polje[1], trim($polje[2]), trim(str_replace('**', '', $polje[4])), $polje[3] !== ''], $polja),
                array_map(fn (array $polje) => [$polje['kljuc'], $polje['oznaka'], $polje['objasnjenje'], $polje['dugo']], $sablon['polja']),
                $sablon['slug'],
            );
            preg_match('/^Objašnjenje: (.*)$/m', $odeljak, $objasnjenje);
            $this->assertStringStartsWith($sablon['objasnjenje'], trim($objasnjenje[1]));
            $poredjeno++;
        }

        // Neprazan skup: poređeno je pet primera (CV, četiri pisma i mejl: šest) i tri šablona.
        $this->assertSame(6 + 3, $poredjeno);
    }

    // Od zaglavlja do sledećeg zaglavlja istog ili višeg nivoa („### " ili „## "); „#### " je deo odeljka.
    private function odeljak(string $dokument, int $pocetak, string $zaglavlje): string
    {
        $ostatak = substr($dokument, $pocetak + strlen($zaglavlje));
        preg_match('/^(?:### |## )/m', $ostatak, $sledeci, PREG_OFFSET_CAPTURE);

        return substr($dokument, $pocetak, strlen($zaglavlje) + (isset($sledeci[0]) ? $sledeci[0][1] : strlen($ostatak)));
    }

    private function prviBlok(string $deo): string
    {
        $pocetak = (int) strpos($deo, "```\n") + 4;

        return substr($deo, $pocetak, (int) strpos($deo, "\n```", $pocetak) - $pocetak);
    }
}
