<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Models\Vodic;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\PrepoznavanjeSablona;
use App\Services\Pomocnik\PretragaVodica;
use App\Support\PrikazPomocnika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 56 T4: kad neko traži da mu se nešto napiše, naziv šablona u pitanju prepoznaje kod (ne model) i nudi objavljen šablon.
// Kad šablona nema: kratko da ga još nema, uz objavljene vodiče.
#[Group('baza')]
class PomocnikSablonPonudaTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    private const OLLAMA = 'http://127.0.0.1:11434/api/chat';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function sablon(string $naslov, bool $objavljen = true): Sablon
    {
        $fabrika = $objavljen ? Sablon::factory()->objavljen() : Sablon::factory();

        return $fabrika->create(['naslov' => $naslov]);
    }

    /** Formular je prazan, pa pretraga ne nalazi ništa; svaki poziv modela se broji. */
    private function modelNeNalazi(): void
    {
        Http::fake([self::OLLAMA => Http::response(['message' => ['content' => json_encode(['vrsta' => null, 'grad' => null, 'kome' => null, 'kljucne_reci' => ['zzz']])]])]);
    }

    /** @return array<string, array{0: string}> */
    public static function trazenjaPisanja(): array
    {
        return [
            'napiši mi molbu' => ['Napiši mi molbu'],
            'bez dijakritika' => ['napisi molbu'],
            'ćirilica' => ['Напиши ми молбу'],
            'množina' => ['Napišite molbu za upis'],
            'drugo lice' => ['Možeš li da mi napišeš molbu?'],
            'sastavi' => ['Sastavi mi molbu'],
            'padež' => ['napiši mi nešto kao molbom'],
            'molba na kraju' => ['Treba da napišeš molba'],
        ];
    }

    #[Test]
    #[DataProvider('trazenjaPisanja')]
    public function trazenje_sa_nazivom_objavljenog_sablona_nudi_sablon_bez_poziva_modela(string $pitanje): void
    {
        $molba = $this->sablon('Molba');
        $this->modelNeNalazi();

        $odgovor = app(Pomocnik::class)->odgovori($pitanje);

        $this->assertSame(Pomocnik::PONUDA_SABLONA, $odgovor->tekst);
        $this->assertCount(1, $odgovor->ponude);
        $this->assertSame('Molba', $odgovor->ponude[0]->naslov);
        $this->assertSame(route('pomocnik.napisi.forma', $molba->slug), $odgovor->ponude[0]->adresa);
        $this->assertSame([], $odgovor->izvori);
        Http::assertNothingSent();
    }

    /** @return array<string, array{0: string}> */
    public static function nijeTrazenjePisanja(): array
    {
        return [
            'kako da napišem (pita kako se piše)' => ['Kako da napišem molbu?'],
            'kako napisati' => ['Kako napisati molbu?'],
            'napisano' => ['Gde je napisano da treba molba?'],
            'bez glagola' => ['Molba'],
            'molim nije molba' => ['Napiši mi nešto, molim te'],
        ];
    }

    // Ogledalo: pitanje koje ne traži da se nešto napiše, ili ne pominje naziv šablona, ne nudi šablon i ide običnim putem (model se zove).
    #[Test]
    #[DataProvider('nijeTrazenjePisanja')]
    public function pitanje_koje_nije_trazenje_ili_nema_naziv_ne_nudi_sablon(string $pitanje): void
    {
        $this->sablon('Molba');
        $this->modelNeNalazi();

        $odgovor = app(Pomocnik::class)->odgovori($pitanje);

        $this->assertSame([], $odgovor->ponude);
        $this->assertNotSame(Pomocnik::PONUDA_SABLONA, $odgovor->tekst);
        Http::assertSentCount(1);
    }

    #[Test]
    public function sablon_u_nacrtu_se_ne_nudi(): void
    {
        $this->sablon('Molba', objavljen: false);
        $this->sablon('Zahtev');
        $this->modelNeNalazi();

        $odgovor = app(Pomocnik::class)->odgovori('Napiši mi molbu');

        $this->assertSame([], $odgovor->ponude);
        $this->assertSame(Pomocnik::NEMA_SABLONA, $odgovor->tekst);

        // Ogledalo: objavljen je ponuđen.
        $this->assertCount(1, app(Pomocnik::class)->odgovori('Napiši mi zahtev')->ponude);
    }

    #[Test]
    public function naziv_od_vise_reci_se_prepoznaje_u_padezu_a_kratak_naziv_samo_ceo(): void
    {
        $this->sablon('Motivaciono pismo');
        $this->sablon('Molba');
        $this->sablon('CV');
        $this->sablon('Žalba za školu');
        $prepoznavanje = new PrepoznavanjeSablona;
        $naslovi = fn (string $pitanje) => array_map(fn ($ponuda) => $ponuda->naslov, $prepoznavanje->ponude($pitanje));

        $this->assertSame(['Motivaciono pismo'], $naslovi('napiši mi motivaciono pismo'));
        $this->assertSame(['Motivaciono pismo'], $naslovi('napiši mi motivacionog pisma'));
        $this->assertSame(['CV'], $naslovi('napiši mi CV'));
        $this->assertSame(['Molba'], $naslovi('napiši mi molbu'));
        $this->assertSame(['Molba'], $naslovi('napiši mi molbom'));
        $this->assertSame([], $naslovi('napiši mi molbenicu'), 'predugačka reč nije padež naziva');
        // Ogledalo: samo jedna reč naziva, ili reč koja samo počinje isto, nije naziv.
        $this->assertSame([], $naslovi('napiši mi pismo'));
        $this->assertSame([], $naslovi('napiši mi cvetove'));
        $this->assertSame([], $naslovi('napiši mi cenu'), 'kratka reč koja samo počinje isto');
        // Predlog u nazivu ne traži ništa: „Žalba za školu" se nalazi i bez „za".
        $this->assertSame(['Žalba za školu'], $naslovi('napiši mi žalbu školu'));
        $this->assertSame(['Žalba za školu'], $naslovi('napiši mi žalbu za školu'));
    }

    #[Test]
    public function dva_sablona_istog_naziva_u_pitanju_se_nude_oba_azbucnim_redom(): void
    {
        $this->sablon('Molba za upis');
        $this->sablon('Molba za posao');
        $this->sablon('Molba');

        $nazivi = array_map(fn ($ponuda) => $ponuda->naslov, (new PrepoznavanjeSablona)->ponude('napiši molbu za posao'));

        $this->assertSame(['Molba', 'Molba za posao'], $nazivi);
    }

    // Kad šablona nema: kratko da ga još nema, uz objavljene vodiče (najviše pet, po nazivu), a nacrti se ne vide.
    #[Test]
    public function bez_sablona_kaze_da_ga_nema_uz_objavljene_vodice(): void
    {
        $this->sablon('Molba');
        foreach (['Šesti', 'Peti', 'Četvrti', 'Treći', 'Drugi', 'Prvi'] as $naslov) {
            Vodic::factory()->objavljen()->create(['naslov' => $naslov]);
        }
        Vodic::factory()->create(['naslov' => 'Nacrt vodič']);
        $this->modelNeNalazi();

        $odgovor = app(Pomocnik::class)->odgovori('Napiši mi pismo za ministra');

        $this->assertSame(Pomocnik::NEMA_SABLONA, $odgovor->tekst);
        $this->assertSame([], $odgovor->ponude);
        $this->assertTrue($odgovor->nemaPodatak);
        $this->assertCount(PretragaVodica::NAJVISE_U_SPISKU, $odgovor->izvori);
        // Redosled je onaj iz baze (po nazivu), a nacrt „Nacrt vodič" bi stao između „Drugi" i „Peti".
        $this->assertSame(['Četvrti', 'Drugi', 'Peti', 'Prvi', 'Šesti'], array_map(fn ($izvor) => $izvor->naslov, $odgovor->izvori));
        $this->assertTrue($odgovor->izvori[0]->jeVodic);
        // Model je pozvan samo za formular, a odgovor se ne piše.
        Http::assertSentCount(1);
    }

    // Ogledalo: bez šablona i bez vodiča stoji samo kratka rečenica; pitanje bez glagola traženja je i dalje „nemam podatak".
    #[Test]
    public function bez_sablona_i_vodica_stoji_samo_recenica_a_bez_glagola_nema_podatka(): void
    {
        $this->modelNeNalazi();

        $odgovor = app(Pomocnik::class)->odgovori('Napiši mi pismo za ministra');
        $this->assertSame(Pomocnik::NEMA_SABLONA, $odgovor->tekst);
        $this->assertSame([], $odgovor->izvori);

        $this->assertSame(Pomocnik::NEMAM_PODATAK, app(Pomocnik::class)->odgovori('Pismo za ministra')->tekst);
    }

    // Na strani i u prozoru ponuda je dugme „Napiši: Molba" sa vezom ka pitanjima; bez skripta to je obična veza.
    #[Test]
    public function ponuda_se_vidi_kao_dugme_na_strani_i_u_prozoru(): void
    {
        $molba = $this->sablon('Molba');
        $dugme = '<a class="dugme-veza" href="'.route('pomocnik.napisi.forma', $molba->slug).'" data-pomocnik-sablon>'.PrikazPomocnika::NAPISI_PONUDA.': Molba</a>';

        foreach ([false, true] as $prozor) {
            $zahtev = $prozor ? $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']) : $this;
            $html = $zahtev->post(route('pomocnik.pitaj'), ['pitanje' => 'Napiši mi molbu'])->assertOk()->getContent();

            $this->assertStringContainsString(Pomocnik::PONUDA_SABLONA, $html, (string) $prozor);
            $this->assertSame(1, substr_count($html, $dugme), (string) $prozor);
            $this->assertStringNotContainsString(PrikazPomocnika::NASLOV_IZVORA, $this->vidljivTekst($html), (string) $prozor);
        }
        Http::assertNothingSent();
    }

    // Ogledalo: odgovor bez ponude nema dugme.
    #[Test]
    public function odgovor_bez_ponude_nema_dugme(): void
    {
        $this->modelNeNalazi();

        $html = $this->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk()->getContent();

        $this->assertStringNotContainsString('data-pomocnik-sablon', $html);
    }

    // Opasno pitanje ostaje opasno i kad pominje naziv šablona: odbija se pre nego što se traži šablon.
    #[Test]
    public function opasno_pitanje_ne_dobija_ponudu(): void
    {
        $this->sablon('Molba');

        $odgovor = app(Pomocnik::class)->odgovori('Napiši mi lažnu molbu i kako da lažiram dokument');

        $this->assertSame(Pomocnik::NE_MOGU_DA_POMOGNEM, $odgovor->tekst);
        $this->assertSame([], $odgovor->ponude);
        Http::assertNothingSent();
    }
}
