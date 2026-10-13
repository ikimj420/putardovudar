<?php

namespace Tests\Feature\Baza;

use App\Enums\Rod;
use App\Enums\StatusObjave;
use App\Models\Sablon;
use App\Models\Vodic;
use App\Support\SklapanjeSablona;
use Database\Seeders\VodiciPrimeriSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 64/T1 i 69/T1: seeder upisuje primere vodičima koji ih nemaju, dodaje šablone kao nacrt i vezuje vodič za šablon samo kad ga nema; ništa postojeće ne menja.
#[Group('baza')]
class VodiciPrimeriSeederTest extends BazaTestCase
{
    use RefreshDatabase;

    private const VODICI = ['kako-napisati-prvi-cv', 'kako-napisati-motivaciono-pismo', 'kako-napisati-email-uz-prijavu', 'kako-traziti-pismo-preporuke'];

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
        $this->assertStringStartsWith('Naslov: Molba za pismo preporuke – Ana Marković', $vodici['kako-traziti-pismo-preporuke']->fresh()->primeri[0]['tekst']);
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

        $this->assertEqualsCanonicalizing(['cv', 'email-uz-prijavu', 'moje-predstavljanje', 'molba-za-preporuku', 'motivaciono-pismo', 'pitanje-o-dokumentu'], Sablon::query()->pluck('slug')->all());
        $this->assertSame('Moj CV', $svoj->fresh()->naslov);
        $this->assertSame('{ime}', $svoj->fresh()->tekst);
        $this->assertSame(StatusObjave::Objavljeno, $svoj->fresh()->status);
        foreach (Sablon::query()->whereIn('slug', ['email-uz-prijavu', 'moje-predstavljanje', 'molba-za-preporuku', 'motivaciono-pismo', 'pitanje-o-dokumentu'])->get() as $sablon) {
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
        $this->assertSame(Sablon::query()->where('slug', 'molba-za-preporuku')->value('id'), $vodici['kako-traziti-pismo-preporuke']->fresh()->sablon_id);
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

        $this->assertCount(6, VodiciPrimeriSeeder::sabloni());
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

        // Neprazan skup: poređeno je petnaest primera (sedam iz paketa 64 i osam iz paketa 69) i šest šablona.
        $this->assertSame(15 + 6, $poredjeno);
    }

    private const NOVI_VODICI = [
        'kako-se-prijaviti-za-stipendiju', 'sta-ako-ti-fali-dokument', 'kako-se-pripremiti-za-razgovor', 'kako-razumeti-uslove-konkursa',
        'kako-poslati-prijavu-online', 'kako-se-prijaviti-za-posao-ili-praksu', 'kako-pripremiti-osnovni-portfolio',
    ];

    /** @return array<string, Vodic> slug → vodič, svi bez primera i bez šablona */
    private function noviVodici(): array
    {
        $vodici = [];

        foreach (self::NOVI_VODICI as $slug) {
            $vodici[$slug] = Vodic::factory()->create(['slug' => $slug, 'naslov' => 'Vodič '.$slug, 'koraci' => ['Prvi korak.'], 'primeri' => null]);
        }

        return $vodici;
    }

    // Paket 69/T1: odobreni primeri za sedam novih vodiča; naslovi i prvi i poslednji red su isti kao u dokumentu (ovo ne traži dokument, za razliku od testa ispod).
    #[Test]
    public function novi_vodici_dobijaju_odobrene_primere_redom_a_vodic_koji_ih_ima_se_ne_dira(): void
    {
        $vodici = $this->noviVodici();
        $svoj = [['naslov' => 'Moj', 'tekst' => 'Primer koji je neko već upisao.']];
        $vodici['kako-razumeti-uslove-konkursa']->update(['primeri' => $svoj]);

        $this->pokreni();

        $ocekivano = [
            'kako-se-prijaviti-za-stipendiju' => [['Primer'], 'Moj spisak za učeničku stipendiju', 'Potvrdu o predaji sačuvala: da / ne'],
            'sta-ako-ti-fali-dokument' => [['Primer'], 'Naslov: Pitanje o dokumentu za konkurs – Ana Marković', 'Ana Marković
060 000 0000'],
            'kako-se-pripremiti-za-razgovor' => [['Primer'], 'Moja priprema za razgovor', 'Ako ne mogu da dođem: javljam se telefonom dan ranije.'],
            'kako-poslati-prijavu-online' => [['Primer'], 'Pre nego što klikneš „Pošalji":', '– Proveri e-poštu, i folder za neželjenu poštu.'],
            'kako-se-prijaviti-za-posao-ili-praksu' => [['Primer'], 'Kad zoveš po oglasu:', '– ponesi ličnu kartu i diplomu ili svedočanstvo (original)'],
            'kako-pripremiti-osnovni-portfolio' => [['Školski rad', 'Rad rukama'], 'Rad: plakat za školsku priredbu, 2025.', 'Šta sam naučio: da prvo dobro pokrijem pod, pa tek onda krečim.'],
        ];

        foreach ($ocekivano as $slug => [$naslovi, $prvi, $poslednji]) {
            $primeri = $vodici[$slug]->fresh()->primeri;

            $this->assertSame($naslovi, array_column($primeri, 'naslov'), $slug);
            $this->assertStringStartsWith($prvi, $primeri[0]['tekst'], $slug);
            $this->assertStringEndsWith($poslednji, $primeri[array_key_last($primeri)]['tekst'], $slug);
        }

        $this->assertStringStartsWith('Rad: okrečen stan kod komšije, 2026. (slike pre i posle)', $vodici['kako-pripremiti-osnovni-portfolio']->fresh()->primeri[1]['tekst']);
        // Ogledalo: vodič koji već ima primer ga zadržava, a vodiča sa istim imenom kog nema seeder ne pravi.
        $this->assertSame($svoj, $vodici['kako-razumeti-uslove-konkursa']->fresh()->primeri);
        $this->assertSame(7, Vodic::query()->count());
    }

    // Paket 69/T1: dva nova šablona ulaze kao nacrt i vezuju se za svoj vodič samo kad on nema nijedan šablon.
    #[Test]
    public function novi_sabloni_se_vezuju_za_svoj_vodic_samo_kad_nema_sablon(): void
    {
        $vodici = $this->noviVodici();
        $tudji = Sablon::factory()->create(['slug' => 'moj-sablon']);
        $vodici['kako-se-pripremiti-za-razgovor']->update(['sablon_id' => $tudji->getKey()]);

        $this->pokreni();

        $dokument = Sablon::query()->where('slug', 'pitanje-o-dokumentu')->firstOrFail();

        $this->assertSame(StatusObjave::Nacrt, $dokument->status);
        $this->assertSame($dokument->getKey(), $vodici['sta-ako-ti-fali-dokument']->fresh()->sablon_id);
        $this->assertSame(StatusObjave::Nacrt, Sablon::query()->where('slug', 'moje-predstavljanje')->firstOrFail()->status);
        // Ogledalo: vodič koji već ima šablon ga zadržava; šablon se ipak pravi (kao nacrt), ali ga nijedan vodič ne dobija.
        $this->assertSame($tudji->getKey(), $vodici['kako-se-pripremiti-za-razgovor']->fresh()->sablon_id);
        $this->assertSame(0, Vodic::query()->where('sablon_id', Sablon::query()->where('slug', 'moje-predstavljanje')->value('id'))->count());
    }

    // Paket 69/T1: „Pitanje o dokumentu" ima oznaku roda {zatražio/zatražila}; provera šablona je prima bez polja za nju, a gotov tekst ima izabran oblik.
    #[Test]
    public function pitanje_o_dokumentu_prima_oznaku_roda_i_daje_izabran_oblik(): void
    {
        $this->pokreni();

        $sablon = Sablon::query()->where('slug', 'pitanje-o-dokumentu')->firstOrFail();
        $unosi = ['ime' => 'Ana Marković', 'konkurs' => 'konkurs za učeničke stipendije', 'dokument' => 'uverenje o državljanstvu', 'rok' => '20. oktobra', 'telefon' => '060 000 0000'];

        $this->assertStringContainsString('{zatražio/zatražila}', $sablon->tekst);
        $this->assertNotContains('zatražio/zatražila', $sablon->kljucevi());
        $sablon->update(['status' => StatusObjave::Objavljeno]);
        $this->assertSame(StatusObjave::Objavljeno, $sablon->fresh()->status);
        $this->assertStringContainsString('uz potvrdu da sam ga zatražila?', SklapanjeSablona::sklopi($sablon, $unosi, Rod::Zenski));
        $this->assertStringContainsString('uz potvrdu da sam ga zatražio?', SklapanjeSablona::sklopi($sablon, $unosi, Rod::Muski));
        $this->assertStringNotContainsString('/', SklapanjeSablona::sklopi($sablon, $unosi, Rod::Muski));
    }

    // Paket 69/T1 i 70/T10: prazno polje „skola" u „Moje predstavljanje" je opciono: red se ne vidi, a ne ostaje ni prazan red ni linija za olovku.
    #[Test]
    public function moje_predstavljanje_bez_skole_nema_ni_prazan_red_ni_liniju(): void
    {
        $this->pokreni();

        $sablon = Sablon::query()->where('slug', 'moje-predstavljanje')->firstOrFail();
        $unosi = ['ime' => 'Marko Jovanović', 'posao' => 'konobar u restoranu „Lipa"', 'skola' => '', 'iskustvo' => 'Tri meseca sam radio u kafani na Zlatiboru.', 'umem' => 'Volim posao sa ljudima.'];

        $bez = SklapanjeSablona::sklopi($sablon, $unosi);
        $sa = SklapanjeSablona::sklopi($sablon, [...$unosi, 'skola' => 'Srednja ugostiteljska škola u Leskovcu.']);

        $this->assertSame("Zovem se Marko Jovanović.\nTri meseca sam radio u kafani na Zlatiboru.\nVolim posao sa ljudima.\nŽelim da radim kao konobar u restoranu „Lipa\".", $bez);
        $this->assertDoesNotMatchRegularExpression('/^$/m', $bez);
        // Ogledalo: sa upisanom školom red je njen tekst, a linije za olovku nema.
        $this->assertStringContainsString("\nSrednja ugostiteljska škola u Leskovcu.\n", $sa);
        $this->assertStringNotContainsString(SklapanjeSablona::PRAZNO, $sa);
        // Ostala polja ostaju linija za olovku kad su prazna.
        $this->assertStringContainsString(SklapanjeSablona::PRAZNO, SklapanjeSablona::sklopi($sablon, [...$unosi, 'umem' => '']));
    }

    // Paket 70/T10: CV iz dokumenta: prazni „kursevi" → odeljak „Kursevi" se ne vidi; prazne „preporuke" → „Preporuke dostupne na zahtev"; ostala prazna polja su linija za olovku.
    #[Test]
    public function cv_bez_kurseva_nema_odeljak_a_bez_preporuka_pise_dostupne_na_zahtev(): void
    {
        $this->pokreni();

        $cv = Sablon::query()->where('slug', 'cv')->firstOrFail();
        $unosi = ['ime' => 'Ana Marković', 'telefon' => '060 000 0000', 'eposta' => 'ana.markovic@example.com', 'mesto' => 'Niš', 'cilj' => 'Želim da radim u knjigovodstvu.', 'iskustvo' => 'Praksa u agenciji.', 'obrazovanje' => '2022–2026. Ekonomska škola, Niš', 'kursevi' => '', 'racunar' => 'Excel – srednji nivo', 'jezici' => 'srpski i romski – maternji', 'preporuke' => ''];

        $tekst = SklapanjeSablona::sklopi($cv, $unosi);

        $this->assertStringNotContainsString('Kursevi', $tekst);
        $this->assertStringNotContainsString("\n\n\n", $tekst);
        $this->assertStringContainsString("Obrazovanje\n2022–2026. Ekonomska škola, Niš\n\nRad na računaru\nExcel – srednji nivo", $tekst);
        $this->assertStringEndsWith("Preporuke\nPreporuke dostupne na zahtev", $tekst);
        $this->assertStringNotContainsString(SklapanjeSablona::PRAZNO, $tekst);

        // Ogledalo: sa kursevima i preporukama odeljak i tekst su tu; prazno „cilj" je i dalje linija za olovku.
        $pun = SklapanjeSablona::sklopi($cv, [...$unosi, 'kursevi' => '2025. Osnove rada u Excelu', 'preporuke' => 'Jelena Stojanović, 060 000 0001', 'cilj' => '']);
        $this->assertStringContainsString("Kursevi\n2025. Osnove rada u Excelu\n\nRad na računaru", $pun);
        $this->assertStringEndsWith("Preporuke\nJelena Stojanović, 060 000 0001", $pun);
        $this->assertStringContainsString("Cilj\n".SklapanjeSablona::PRAZNO."\n", $pun);
    }

    // Paket 70/T10: motivaciono pismo iz dokumenta: prazno „kome" → „Poštovani"; upisano „kome" ostaje.
    #[Test]
    public function motivaciono_pismo_bez_kome_pocinje_sa_postovani(): void
    {
        $this->pokreni();

        $pismo = Sablon::query()->where('slug', 'motivaciono-pismo')->firstOrFail();
        $unosi = ['ime' => 'Ana Marković', 'prilika' => 'stipendija za studente', 'oglas' => 'Sajt škole', 'kome' => '', 'skola' => 'Ekonomski fakultet u Nišu', 'iskustvo' => 'Praksa u agenciji.', 'zasto' => 'Želim da završim fakultet.', 'kraj' => 'Niš, 20. oktobar'];

        $this->assertStringStartsWith("Poštovani,\n\nprijavljujem se za stipendija za studente.", SklapanjeSablona::sklopi($pismo, $unosi));
        $this->assertStringStartsWith("Poštovana profesorko Petrović,\n\nprijavljujem se", SklapanjeSablona::sklopi($pismo, [...$unosi, 'kome' => 'Poštovana profesorko Petrović']));
    }

    // Paket 70/T10: podešavanje stoji uz polje (u podacima šablona), ne u kodu po imenu šablona: ista tri polja sa istim pravilima rade na šablonu koji seeder ne poznaje.
    #[Test]
    public function pravilo_stoji_uz_polje_a_ne_uz_ime_sablona(): void
    {
        $sablon = Sablon::factory()->create(['slug' => 'neki-moj-sablon', 'naslov' => 'Moj', 'objasnjenje' => 'Moj.', 'status' => StatusObjave::Nacrt,
            'polja' => [['kljuc' => 'a', 'oznaka' => 'A', 'dugo' => false, 'ako_prazno' => 'Zdravo'], ['kljuc' => 'b', 'oznaka' => 'B', 'dugo' => false, 'opciono' => true], ['kljuc' => 'c', 'oznaka' => 'C', 'dugo' => false]],
            'tekst' => "{a},\n\nodeljak\n{b}\n\n{c}"]);

        $this->assertSame("Zdravo,\n\n".SklapanjeSablona::PRAZNO, SklapanjeSablona::sklopi($sablon, []));

        // Isto ime šablona kao u seederu, ali bez zadatih pravila: kao do sada, sve prazno je linija za olovku.
        $sablon->update(['slug' => 'cv', 'polja' => [['kljuc' => 'kursevi', 'oznaka' => 'Kursevi', 'dugo' => false]], 'tekst' => "Kursevi\n{kursevi}"]);
        $this->assertSame("Kursevi\n".SklapanjeSablona::PRAZNO, SklapanjeSablona::sklopi($sablon->fresh(), []));
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
