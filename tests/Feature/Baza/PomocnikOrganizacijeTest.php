<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusObjave;
use App\Enums\VrstaOrganizacije;
use App\Enums\VrstaPrilike;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Pomocnik\Formular;
use App\Services\Pomocnik\OdgovorPomocnika;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\PretragaOrganizacija;
use App\Support\PrikazOrganizacija;
use Database\Seeders\OrganizacijeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 24: pomoćnik traži i organizacije (naziv i usluge), samo objavljene, najviše tri; grad se ne koristi.
#[Group('baza')]
class PomocnikOrganizacijeTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    /** @var array<string, mixed> */
    private array $formular = [];

    private string $odgovorModela = 'Postoji organizacija koja pomaže.';

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

    /** @param  array<string, mixed>  $dopuna */
    private function nsz(array $dopuna = []): Organizacija
    {
        return Organizacija::factory()->objavljena()->create([...[
            'naziv' => 'Nacionalna služba za zapošljavanje', 'vrsta' => VrstaOrganizacije::JavnaInstitucija,
            'kratak_opis' => 'Javna služba koja pomaže nezaposlenima; rok za probu je 20. oktobra 2026.',
            'opis' => 'Dugačak opis organizacije.', 'mesto' => 'Mreža filijala širom Srbije.', 'telefon' => '011/555-777',
            'sajt' => 'https://www.nsz.gov.rs/', 'usluge' => ['Podrška za zapošljavanje', 'CV podrška', 'Savetovanje'],
            'beleska' => 'Tajna beleška za admina.',
        ], ...$dopuna]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...[
            'naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-11-15',
            'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis',
        ], ...$dopuna]);
    }

    /** @param  array<string, mixed>  $formular */
    private function model(array $formular, string $odgovor = 'Postoji organizacija koja pomaže.'): void
    {
        $this->formular = $formular;
        $this->odgovorModela = $odgovor;
    }

    private function pozivaZaOdgovor(): int
    {
        return Http::recorded(fn (Request $zahtev) => $zahtev->url() === self::OLLAMA && ! array_key_exists('format', $zahtev->data()))->count();
    }

    private function pitaj(string $pitanje = 'Gde mogu da nađem pomoć oko zapošljavanja?'): OdgovorPomocnika
    {
        return app(Pomocnik::class)->odgovori($pitanje);
    }

    // Merilo paketa: pitanje o zapošljavanju nalazi NSZ kad je objavljena.
    #[Test]
    public function pitanje_o_zaposljavanju_nalazi_nsz_kad_je_objavljena_i_daje_izvor_sa_oznakom_i_linkovima(): void
    {
        $nsz = $this->nsz();
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $odgovor = $this->pitaj();

        $this->assertFalse($odgovor->nemaPodatak);
        $this->assertCount(1, $odgovor->izvori);
        $izvor = $odgovor->izvori[0];
        $this->assertTrue($izvor->jeOrganizacija);
        $this->assertFalse($izvor->jeVodic);
        $this->assertSame('Nacionalna služba za zapošljavanje', $izvor->naslov);
        $this->assertSame(route('organizacije.show', $nsz->slug), $izvor->adresa);
        $this->assertSame('https://www.nsz.gov.rs/', $izvor->zvanicniLink);
        $this->assertSame('www.nsz.gov.rs', $izvor->zvanicniNaziv);
        $this->assertNull($izvor->rok);
        $this->assertSame('Postoji organizacija koja pomaže.', $odgovor->tekst);
        $this->assertNull($odgovor->razlogSablona);
    }

    // Isto merilo, nad pravim podacima iz seedera: NSZ se objavi, ostalih pet ostaje nacrt.
    #[Test]
    public function nad_podacima_iz_seedera_nsz_se_nalazi_tek_kad_je_objavljena_a_nacrti_nikad(): void
    {
        $this->seed(OrganizacijeSeeder::class);
        $this->assertSame(6, Organizacija::query()->count());
        $this->assertSame(0, Organizacija::javne()->count());
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $nacrt = $this->pitaj();
        $this->assertTrue($nacrt->nemaPodatak);
        $this->assertSame([], $nacrt->izvori);

        Organizacija::query()->where('naziv', 'Nacionalna služba za zapošljavanje')->firstOrFail()->update(['status' => StatusObjave::Objavljeno]);

        $objavljena = $this->pitaj();
        $this->assertSame(['Nacionalna služba za zapošljavanje'], array_map(fn ($izvor) => $izvor->naslov, $objavljena->izvori));
    }

    #[Test]
    public function nacrt_organizacija_nikad_ne_ulazi_ni_kad_se_poklapa_sa_svim_recima(): void
    {
        $this->nsz(['status' => StatusObjave::Nacrt]);
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $odgovor = $this->pitaj();

        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertSame(Pomocnik::NEMAM_PODATAK, $odgovor->tekst);
        $this->assertSame([], $odgovor->izvori);
        $this->assertSame(0, $this->pozivaZaOdgovor());
    }

    #[Test]
    public function grad_se_za_organizacije_ne_koristi(): void
    {
        $this->nsz(['mesto' => 'Beograd, Terazije 39']);
        $pretraga = app(PretragaOrganizacija::class);

        // Isti rezultat sa gradom koji se ne pominje u mestu, sa gradom koji se pominje i bez grada.
        foreach ([null, 'Niš', 'Beograd'] as $grad) {
            $this->assertCount(1, $pretraga->pronadji(new Formular(grad: $grad, kljucneReci: ['zapošljavanje'])), (string) $grad);
        }
        // Ogledalo: sam grad bez ključnih reči nije pretraga.
        $this->assertCount(0, $pretraga->pronadji(new Formular(grad: 'Beograd')));
    }

    #[Test]
    public function bez_kljucnih_reci_organizacije_se_ne_traze(): void
    {
        $this->nsz();
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš']);

        $odgovor = $this->pitaj('Ima li posla u Nišu?');

        $this->assertSame([false], array_map(fn ($izvor) => $izvor->jeOrganizacija, $odgovor->izvori));
    }

    #[Test]
    public function reci_se_traze_samo_u_nazivu_i_uslugama_i_sve_moraju_da_pisu(): void
    {
        $this->nsz();
        $pretraga = app(PretragaOrganizacija::class);

        $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: ['služba'])));
        $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: ['CV'])));
        $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: ['zapošljavanje', 'CV'])));
        $this->assertCount(1, $pretraga->pronadji(new Formular(kljucneReci: ['savetovanje', 'nacionalna'])));
        // Ogledalo: ako jedna reč ne piše ni u nazivu ni u uslugama, organizacija ne ulazi.
        $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: ['zapošljavanje', 'knjigovođa'])));
        // „Nezaposlenima" je samo u kratkom opisu, „dugačak" u opisu, „Mreža" u mestu, „555" u telefonu, „nsz" u sajtu, „tajna" u belešci.
        foreach (['nezaposlenima', 'dugačak', 'filijala', '555', 'nsz', 'tajna'] as $rec) {
            $this->assertCount(0, $pretraga->pronadji(new Formular(kljucneReci: [$rec])), $rec);
        }
    }

    #[Test]
    public function najvise_tri_organizacije_po_abecedi_naziva(): void
    {
        foreach (['Peta za CV', 'Prva za CV', 'Treca za CV', 'Druga za CV', 'Cetvrta za CV'] as $naziv) {
            Organizacija::factory()->objavljena()->create(['naziv' => $naziv, 'usluge' => ['CV podrška']]);
        }

        $nazivi = app(PretragaOrganizacija::class)->pronadji(new Formular(kljucneReci: ['CV']))->pluck('naziv')->all();

        $this->assertSame(['Cetvrta za CV', 'Druga za CV', 'Peta za CV'], $nazivi);
    }

    #[Test]
    public function nemam_podatak_samo_kad_nema_ni_prilika_ni_vodica_ni_organizacija_i_drugi_poziv_se_ne_pravi(): void
    {
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $nista = $this->pitaj();
        $this->assertTrue($nista->nemaPodatak);
        $this->assertSame(0, $this->pozivaZaOdgovor());

        $this->nsz();
        $samoOrganizacija = $this->pitaj();
        $this->assertFalse($samoOrganizacija->nemaPodatak);
        $this->assertSame(1, $this->pozivaZaOdgovor());
    }

    #[Test]
    public function prilika_vodic_i_organizacija_zajedno_daju_tri_izvora_tim_redom(): void
    {
        $this->prilika(['kratak_opis' => 'Puno radno vreme, CV u prilogu.']);
        Vodic::factory()->objavljen()->create(['naslov' => 'Kako napisati prvi CV', 'kratak_opis' => 'O CV-u.']);
        $this->nsz();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš', 'kljucne_reci' => ['CV']]);

        $odgovor = $this->pitaj('Kako da napišem CV za posao u Nišu?');

        $this->assertSame([[false, false], [true, false], [false, true]], array_map(fn ($izvor) => [$izvor->jeVodic, $izvor->jeOrganizacija], $odgovor->izvori));
    }

    #[Test]
    public function provera_odgovora_vazi_i_za_organizacije(): void
    {
        $nsz = $this->nsz();

        // Izmišljen datum i link: šablon sa organizacijom.
        $this->model(['kljucne_reci' => ['zapošljavanje']], 'Prijave su do 25. oktobra 2026.');
        $this->assertSame('datum kog nema u zapisima', $this->pitaj()->razlogSablona);

        $this->model(['kljucne_reci' => ['zapošljavanje']], 'Pročitaj na https://lazno.rs/nsz.');
        $odgovor = $this->pitaj();
        $this->assertSame('link kog nema u zapisima', $odgovor->razlogSablona);
        $this->assertSame(Pomocnik::SABLON_NASLOV_ORGANIZACIJE."\n• Nacionalna služba za zapošljavanje", $odgovor->tekst);

        // Ogledalo: datum iz kratkog opisa, adresa naše strane i adresa sajta organizacije prolaze.
        foreach ([
            'Do 20. oktobra 2026. otvori '.route('organizacije.show', $nsz->slug).'.',
            'Sajt je https://www.nsz.gov.rs/ i tamo ima sve.',
            'Sajt je www.nsz.gov.rs.',
        ] as $tekst) {
            $this->model(['kljucne_reci' => ['zapošljavanje']], $tekst);
            $this->assertNull($this->pitaj()->razlogSablona, $tekst);
        }
    }

    #[Test]
    public function odgovor_koji_kaze_da_nema_podatka_uz_organizaciju_menja_sablon(): void
    {
        $this->nsz();
        $this->model(['kljucne_reci' => ['zapošljavanje']], 'Nemam podatak o tome.');

        $odgovor = $this->pitaj();

        $this->assertSame('odgovor kaže da nema podatka', $odgovor->razlogSablona);
        $this->assertStringStartsWith(Pomocnik::SABLON_NASLOV_ORGANIZACIJE, $odgovor->tekst);
        $this->assertCount(1, $odgovor->izvori);
    }

    #[Test]
    public function modelu_idu_samo_naziv_vrsta_kratak_opis_i_usluge_a_ne_opis_mesto_telefon_sajt_ni_beleska(): void
    {
        $this->nsz();
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $this->pitaj();

        Http::assertSent(function (Request $zahtev) {
            $telo = $zahtev->data();

            if (array_key_exists('format', $telo)) {
                return false;
            }

            $poruka = $telo['messages'][1]['content'];

            return str_contains($poruka, "Organizacije:\nNaziv: Nacionalna služba za zapošljavanje; Vrsta: Javna institucija; Kratak opis: Javna služba koja pomaže nezaposlenima; rok za probu je 20. oktobra 2026.; Usluge: Podrška za zapošljavanje, CV podrška, Savetovanje")
                && ! str_contains($poruka, 'Dugačak opis')
                && ! str_contains($poruka, 'Mreža filijala')
                && ! str_contains($poruka, '011/555-777')
                && ! str_contains($poruka, 'nsz.gov.rs')
                && ! str_contains($poruka, 'Tajna beleška')
                && ! str_contains($poruka, 'Prilike:')
                && ! str_contains($poruka, 'Vodiči:')
                && str_contains($telo['messages'][0]['content'], 'samo iz priloženih prilika, vodiča i organizacija');
        });
    }

    #[Test]
    public function strana_pomocnika_pokazuje_organizaciju_kao_karticu_sa_oznakom_i_linkovima_bez_roka(): void
    {
        $nsz = $this->nsz();
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Gde mogu da nađem pomoć oko zapošljavanja?'])->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('<a href="'.route('organizacije.show', $nsz->slug).'">Nacionalna služba za zapošljavanje</a>', $html);
        $this->assertStringContainsString('<span class="oznaka">'.PrikazOrganizacija::OZNAKA.'</span>', $html);
        // Tekst oznake traži paket 24 („Organizacija"), pa se proverava i doslovno, a ne samo preko konstante.
        $this->assertStringContainsString('<span class="oznaka">Organizacija</span>', $html);
        $this->assertStringContainsString('<a href="https://www.nsz.gov.rs/" rel="noopener noreferrer nofollow" target="_blank">www.nsz.gov.rs</a>', $html);
        $this->assertStringContainsString(PrikazOrganizacija::SAJT.': www.nsz.gov.rs', $tekst);
        $this->assertStringNotContainsString('Zvanični izvor:', $tekst);
        $this->assertStringNotContainsString('Rok:', $tekst);
        $this->assertStringNotContainsString('Bez roka', $tekst);
        $this->assertStringNotContainsString('Tajna beleška', $html);
        $this->assertStringNotContainsString('011/555-777', $html);
    }

    // Ogledalo: prilika na istoj strani i dalje nosi rok i zvanični izvor, a ne oznaku organizacije.
    #[Test]
    public function prilika_na_strani_nema_oznaku_organizacije_a_nosi_rok_i_zvanicni_izvor(): void
    {
        $this->prilika();
        $this->model(['vrsta' => 'posao', 'grad' => 'Niš']);

        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringNotContainsString(PrikazOrganizacija::OZNAKA, $tekst);
        $this->assertStringContainsString('Rok: 15.11.2026.', $tekst);
        $this->assertStringContainsString('Zvanični izvor: Probni izvor', $tekst);
    }

    // Sajt u bazi može da stigne i mimo pravila objave; ako nije veb adresa, link se ne piše.
    #[Test]
    public function sajt_koji_nije_veb_adresa_se_ne_pretvara_u_link(): void
    {
        $nsz = $this->nsz();
        Organizacija::query()->whereKey($nsz->id)->update(['sajt' => 'javascript:alert(1)']);
        $this->model(['kljucne_reci' => ['zapošljavanje']]);

        $odgovor = $this->pitaj();

        $this->assertNull($odgovor->izvori[0]->zvanicniLink);
        $this->assertNull($odgovor->izvori[0]->zvanicniNaziv);

        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Gde mogu da nađem pomoć oko zapošljavanja?'])->assertOk()->getContent();
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
