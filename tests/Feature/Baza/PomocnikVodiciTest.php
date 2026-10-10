<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\OdgovorPomocnika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\PretragaVodica;
use App\Support\PrikazVodica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

#[Group('baza')]
class PomocnikVodiciTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    /** @var array<string, mixed> */
    private array $formular = [];

    private string $odgovorModela = 'Postoji vodič o tome.';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
        app('url')->forceRootUrl('https://nas-sajt.rs');

        // Jedan lažni model za ceo test; model() samo menja šta on vraća (drugi Http::fake za isti URL se ne računa).
        Http::fake([self::OLLAMA => function (Request $zahtev) {
            return array_key_exists('format', $zahtev->data())
                ? Http::response(['message' => ['content' => json_encode($this->formular + ['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => []])]])
                : Http::response(['message' => ['content' => $this->odgovorModela]]);
        }]);
    }

    private function cv(array $dopuna = []): Vodic
    {
        return Vodic::factory()->objavljen()->create([...[
            'naslov' => 'Kako napisati prvi CV', 'kratak_opis' => 'Šta staviti u CV kada nemaš radno iskustvo.',
            'tekst' => 'Dugačak tekst vodiča.', 'koraci' => ['Napiši kontakt.'], 'beleska' => 'Tajna beleška za admina.',
        ], ...$dopuna]);
    }

    private function prilika(): Prilika
    {
        return Prilika::factory()->objavljena()->create([
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ]);
    }

    /** @param  array<string, mixed>  $formular */
    private function model(array $formular, string $odgovor = 'Postoji vodič o tome.'): void
    {
        $this->formular = $formular;
        $this->odgovorModela = $odgovor;
    }

    private function pozivaZaOdgovor(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA && ! array_key_exists('format', $zahtev->data()))->count();
    }

    private function pitaj(string $pitanje = 'Kako da napišem CV?'): OdgovorPomocnika
    {
        return app(Pomocnik::class)->odgovori($pitanje);
    }

    #[Test]
    public function pitanje_o_cv_u_daje_vodic_o_cv_u_kao_izvor_sa_linkom_na_nasu_stranu(): void
    {
        $cv = $this->cv();
        $this->model(['kljucne_reci' => ['CV']]);

        $odgovor = $this->pitaj();

        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertCount(1, $odgovor->izvori);
        $this->assertTrue($odgovor->izvori[0]->jeVodic);
        $this->assertSame('Kako napisati prvi CV', $odgovor->izvori[0]->naslov);
        $this->assertSame(route('vodici.show', $cv->slug), $odgovor->izvori[0]->adresa);
        $this->assertNull($odgovor->izvori[0]->rok);
        $this->assertNull($odgovor->izvori[0]->zvanicniLink);
        $this->assertSame('Postoji vodič o tome.', $odgovor->tekst);
    }

    #[Test]
    public function nacrt_vodic_nikad_ne_ulazi(): void
    {
        $this->cv(['naslov' => 'Kako napisati CV za inostranstvo', 'status' => StatusObjave::Nacrt]);
        $this->model(['kljucne_reci' => ['CV']]);

        $odgovor = $this->pitaj();

        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertSame(Pomocnik::NEMAM_PODATAK, $odgovor->tekst);
        $this->assertSame([], $odgovor->izvori);
    }

    #[Test]
    public function nemam_podatak_samo_kad_nema_ni_prilika_ni_vodica_i_drugi_poziv_se_ne_pravi(): void
    {
        $this->cv();
        $this->prilika();
        $this->model(['vrsta' => 'stipendija', 'kljucne_reci' => ['pasulj']]);

        $odgovor = $this->pitaj('Kako se kuva pasulj?');

        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertSame(0, $this->pozivaZaOdgovor());
    }

    // Ogledala: samo vodič, samo prilika i oboje daju odgovor.
    #[Test]
    public function samo_prilika_samo_vodic_i_oboje_daju_odgovor_i_izvore_tim_redom(): void
    {
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš'], 'Ima posla.');
        $this->assertTrue($this->pitaj('Ima li posla u Nišu?')->nemaPodatak);

        $this->prilika();
        $samoPrilika = $this->pitaj('Ima li posla u Nišu?');
        $this->assertFalse($samoPrilika->nemaPodatak);
        $this->assertSame([false], array_map(fn ($izvor) => $izvor->jeVodic, $samoPrilika->izvori));

        $this->model(['kljucne_reci' => ['CV']], 'Ima vodič.');
        $this->cv();
        $samoVodic = $this->pitaj('Kako da napišem CV?');
        $this->assertSame([true], array_map(fn ($izvor) => $izvor->jeVodic, $samoVodic->izvori));
    }

    #[Test]
    public function prilika_i_vodic_zajedno_daju_dva_izvora_prilika_prva(): void
    {
        $this->cv(['naslov' => 'Kako napisati CV za posao u skladištu', 'kratak_opis' => 'CV za posao radnika.']);
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kljucne_reci' => ['CV']]);

        $this->assertSame([], app(PretragaVodica::class)->pronadji(new Formular(kljucneReci: ['knjigovođa']))->all());

        $this->model(['kljucne_reci' => ['CV']], 'Ima vodič o CV-u.');
        Prilika::query()->update(['kratak_opis' => 'Traži se radnik, pošalji CV.']);
        $odgovor = $this->pitaj('Ima li posla u skladištu i kako da napišem CV?');

        $this->assertSame([false, true], array_map(fn ($izvor) => $izvor->jeVodic, $odgovor->izvori));
    }

    #[Test]
    public function bez_kljucnih_reci_vodici_se_ne_traze(): void
    {
        $this->cv();
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš']);

        $odgovor = $this->pitaj('Ima li posla u Nišu?');

        $this->assertSame([false], array_map(fn ($izvor) => $izvor->jeVodic, $odgovor->izvori));
    }

    // Od paketa 23 vodiči se traže samo po naslovu (Ivanova odluka, merenje u paketu 21): kratak opis i tekst se ne pretražuju.
    #[Test]
    public function sve_kljucne_reci_moraju_da_pisu_u_naslovu_a_ne_u_kratkom_opisu_ni_tekstu(): void
    {
        $this->cv();
        $pretraga = app(PretragaVodica::class);

        $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: ['CV', 'prvi'])));
        $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: ['CV'])));
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['CV', 'knjigovođa'])));
        // „Iskustvo" je samo u kratkom opisu, a „dugačak" samo u tekstu vodiča: ni jedno ni drugo se ne pretražuje.
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['CV', 'iskustvo'])));
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['iskustvo'])));
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['dugačak'])));
    }

    // Merenje iz paketa 21: „CV" je vraćao i vodiče o e-pošti i prijavi, jer im opis pominje CV.
    #[Test]
    public function reč_u_opisu_drugog_vodica_ga_ne_dovodi_u_odgovor(): void
    {
        $this->cv();
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati email uz prijavu', 'kratak_opis' => 'Kratka poruka koja prati CV.']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako se prijaviti za posao ili praksu', 'kratak_opis' => 'Od CV-a do razgovora.']);

        $naslovi = app(PretragaVodica::class)->pronadji(new Formular(kljucneReci: ['CV']))->pluck('naslov')->all();

        $this->assertSame(['Kako napisati prvi CV'], $naslovi);
    }

    // Merenje iz paketa 21: „dokumenta" nije stizalo do vodiča „Šta ako ti fali dokument?", jer su tri druga imala reč u opisu.
    #[Test]
    public function vodic_sa_recju_u_naslovu_ne_ostaje_iza_vodica_sa_recju_u_opisu(): void
    {
        foreach (['Kako poslati prijavu online', 'Kako se prijaviti za stipendiju', 'Kako tražiti pismo preporuke', 'Kako napisati email uz prijavu'] as $naslov) {
            Vodic::factory()->objavljen()->create(['naslov' => $naslov, 'kratak_opis' => 'Koja dokumenta treba priložiti.']);
        }
        Vodic::factory()->objavljen()->create(['naslov' => 'Šta ako ti fali dokument?', 'kratak_opis' => 'Kako da dođeš do kopije.']);

        $naslovi = app(PretragaVodica::class)->pronadji(new Formular(kljucneReci: ['dokumenta']))->pluck('naslov')->all();

        $this->assertSame(['Šta ako ti fali dokument?'], $naslovi);
    }

    // „Vodič" ponavlja vrstu strane koja se traži, pa ne sužava pretragu (kao reč koja ponavlja vrstu ili grad kod prilika).
    #[Test]
    public function rec_vodic_ne_suzava_pretragu_u_bilo_kom_padezu(): void
    {
        $this->cv();
        $pretraga = app(PretragaVodica::class);

        foreach ([['vodič', 'CV'], ['vodiči', 'CV'], ['vodiča', 'CV'], ['vodičima', 'CV'], ['Vodič CV'], ['vodic', 'cv']] as $reci) {
            $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: $reci)), implode(' + ', $reci));
        }
        // Ogledalo: ostale reči i dalje sužavaju, a sama reč „vodič" nije pretraga.
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['vodič', 'knjigovođa'])));
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['vodič'])));
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['vodiči', 'vodič'])));
    }

    // Ogledalo: otpada samo reč „vodič", ne svaka reč koja počinje na „vod".
    #[Test]
    public function recka_koja_samo_pocinje_kao_vodic_i_dalje_trazi(): void
    {
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako da prijaviš kvar na vodovodu', 'kratak_opis' => 'Za stanare.']);

        $this->assertCount(1, app(PretragaVodica::class)->pronadji(new Formular(kljucneReci: ['vodovod'])));
        $this->assertCount(0, app(PretragaVodica::class)->pronadji(new Formular(kljucneReci: ['vodovod', 'CV'])));
    }

    #[Test]
    public function najvise_tri_vodica_po_abecedi(): void
    {
        foreach (['Peti CV vodič', 'Prvi CV vodič', 'Treci CV vodič', 'Drugi CV vodič', 'Cetvrti CV vodič'] as $naslov) {
            Vodic::factory()->objavljen()->create(['naslov' => $naslov, 'kratak_opis' => 'O CV-u.']);
        }

        $naslovi = app(PretragaVodica::class)->pronadji(new Formular(kljucneReci: ['CV']))->pluck('naslov')->all();

        $this->assertSame(['Cetvrti CV vodič', 'Drugi CV vodič', 'Peti CV vodič'], $naslovi);
    }

    #[Test]
    public function provera_odgovora_vazi_i_za_vodice(): void
    {
        $cv = $this->cv(['kratak_opis' => 'Šta staviti u CV; rok za probu je 20. oktobra 2026.']);

        // Izmišljen datum i link: šablon sa vodičem.
        $this->model(['kljucne_reci' => ['CV']], 'Vodič je gotov do 25. oktobra 2026.');
        $this->assertSame('datum kog nema u zapisima', $this->pitaj()->razlogSablona);

        $this->model(['kljucne_reci' => ['CV']], 'Pročitaj na https://lazno.rs/cv.');
        $odgovor = $this->pitaj();
        $this->assertSame('link kog nema u zapisima', $odgovor->razlogSablona);
        $this->assertSame(Pomocnik::SABLON_NASLOV_VODICI."\n• Kako napisati prvi CV", $odgovor->tekst);

        // Ogledalo: datum iz vodiča i adresa naše strane vodiča prolaze.
        $this->model(['kljucne_reci' => ['CV']], 'Do 20. oktobra 2026. pročitaj '.route('vodici.show', $cv->slug).'.');
        $this->assertNull($this->pitaj()->razlogSablona);
    }

    #[Test]
    public function modelu_idu_samo_naslov_i_kratak_opis_vodica_a_ne_tekst_koraci_ni_beleska(): void
    {
        $this->cv();
        $this->model(['kljucne_reci' => ['CV']]);

        $this->pitaj();

        Http::assertSent(function (Request $zahtev) {
            $telo = $zahtev->data();

            if (array_key_exists('format', $telo)) {
                return false;
            }

            $poruka = $telo['messages'][1]['content'];

            return str_contains($poruka, "Vodiči:\nNaslov: Kako napisati prvi CV; Kratak opis: Šta staviti u CV kada nemaš radno iskustvo.")
                && ! str_contains($poruka, 'Dugačak tekst')
                && ! str_contains($poruka, 'Napiši kontakt')
                && ! str_contains($poruka, 'Tajna beleška')
                && ! str_contains($poruka, 'Prilike:')
                && str_contains($telo['messages'][0]['content'], 'samo iz priloženih prilika, vodiča i organizacija');
        });
    }

    #[Test]
    public function strana_pomocnika_pokazuje_vodic_kao_karticu_sa_oznakom_i_linkom_bez_roka(): void
    {
        $cv = $this->cv();
        $this->model(['kljucne_reci' => ['CV']]);

        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Kako da napišem CV?'])->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('<a href="'.route('vodici.show', $cv->slug).'">Kako napisati prvi CV</a>', $html);
        $this->assertStringContainsString('<span class="oznaka">'.PrikazVodica::OZNAKA.'</span>', $html);
        $this->assertStringNotContainsString('Rok:', $tekst);
        $this->assertStringNotContainsString('Bez roka', $tekst);
        $this->assertStringNotContainsString('Tajna beleška', $html);
    }
}
