<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPredloga;
use App\Models\KalendarStavka;
use App\Models\Predlog;
use App\Models\Prilika;
use App\Support\PrikazPredloga;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 79: posetilac predlaže priliku (samo link i napomena); predlog nije prilika i ništa se ne objavljuje.
#[Group('baza')]
class PredloziTest extends BazaTestCase
{
    use RefreshDatabase;

    private const LINK = 'https://primer.rs/konkurs-za-stipendije';

    /** @param  array<string, mixed>  $podaci */
    private function posalji(array $podaci, string $adresa = '198.51.100.7'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $adresa])->post(route('predlozi.posalji'), $podaci);
    }

    #[Test]
    public function forma_ima_naslov_uvod_dva_polja_i_natpis_o_cuvanju(): void
    {
        $odgovor = $this->get(route('predlozi.forma'))->assertOk()->assertSeeInOrder([
            PrikazPredloga::NASLOV, PrikazPredloga::UVOD, PrikazPredloga::OZNAKA_LINKA, PrikazPredloga::OZNAKA_NAPOMENE, PrikazPredloga::DUGME, PrikazPredloga::NE_CUVAMO,
        ])->assertDontSee(PrikazPredloga::HVALA);

        // Bez imena, mejla i telefona: osim zaštitnog polja, forma šalje tačno link i napomenu (plutajući prozor pomoćnika ima svoju formu, pa se čita samo ova).
        $this->assertSame(1, preg_match('#<form\b[^>]*action="'.preg_quote(route('predlozi.posalji'), '#').'"[^>]*>(.*?)</form>#s', $odgovor->getContent(), $forma));
        preg_match_all('/<(?:input|textarea|select)\b[^>]*\sname="([^"]+)"/', $forma[1], $polja);
        $this->assertSame(['_token', 'link', 'napomena'], $polja[1]);
        $this->assertSame('Predloži priliku', PrikazPredloga::NASLOV);
        $this->assertSame('Hvala! Predlog je stigao.', PrikazPredloga::HVALA);
    }

    #[Test]
    public function ispravan_link_se_cuva_a_posle_slanja_pise_hvala(): void
    {
        $this->posalji(['link' => self::LINK, 'napomena' => 'Prijave su do 15. novembra.'])->assertRedirect(route('predlozi.forma'));

        $predlog = Predlog::query()->sole();
        $this->assertSame(self::LINK, $predlog->link);
        $this->assertSame('Prijave su do 15. novembra.', $predlog->napomena);
        $this->assertSame(StatusPredloga::Nov, $predlog->status);

        $this->get(route('predlozi.forma'))->assertSee(PrikazPredloga::HVALA);
        // Ogledalo: zahvalnica se pokazuje jednom, ne i pri sledećem otvaranju.
        $this->get(route('predlozi.forma'))->assertDontSee(PrikazPredloga::HVALA);
    }

    #[Test]
    public function napomena_nije_obavezna_a_razmaci_oko_linka_se_skidaju(): void
    {
        $this->posalji(['link' => '  '.self::LINK.'  '])->assertRedirect(route('predlozi.forma'));

        $predlog = Predlog::query()->sole();
        $this->assertSame(self::LINK, $predlog->link);
        $this->assertNull($predlog->napomena);
    }

    /** @return array<string, array{0: string}> */
    public static function losiLinkovi(): array
    {
        return [
            'bez adrese' => ['nije link'],
            'bez šeme' => ['primer.rs/konkurs'],
            'ftp' => ['ftp://primer.rs/konkurs'],
            'javascript' => ['javascript:alert(1)'],
            'samo šema' => ['https://'],
            'mejl' => ['mailto:neko@primer.rs'],
        ];
    }

    #[Test]
    #[DataProvider('losiLinkovi')]
    public function los_link_se_odbija_sa_porukom_a_uneto_ostaje_u_polju(string $link): void
    {
        $this->posalji(['link' => $link, 'napomena' => 'Moja napomena'])
            ->assertStatus(422)
            ->assertSee(PrikazPredloga::GRESKA_LINK)
            ->assertSee('Moja napomena')
            ->assertDontSee(PrikazPredloga::HVALA);

        $this->assertSame(0, Predlog::query()->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function praznaPolja(): array
    {
        return ['bez linka' => [[]], 'prazan link' => [['link' => '']], 'samo razmaci' => [['link' => '   ']]];
    }

    /** @param  array<string, mixed>  $podaci */
    #[Test]
    #[DataProvider('praznaPolja')]
    public function prazan_link_se_odbija_sa_porukom(array $podaci): void
    {
        $this->posalji($podaci)->assertStatus(422)->assertSee(PrikazPredloga::GRESKA_PRAZNO);

        $this->assertSame(0, Predlog::query()->count());
    }

    // Ogledalo: link koji stiže kao niz ne ruši stranu, nego se odbija kao neispravan.
    #[Test]
    public function link_koji_je_niz_se_odbija_a_ne_ruši_stranu(): void
    {
        $this->posalji(['link' => [self::LINK]])->assertStatus(422)->assertSee(PrikazPredloga::GRESKA_LINK);

        $this->assertSame(0, Predlog::query()->count());
    }

    #[Test]
    public function napomena_ima_najvise_petsto_znakova_a_broje_se_slova_ne_bajtovi(): void
    {
        $this->posalji(['link' => self::LINK, 'napomena' => str_repeat('č', 501)])
            ->assertStatus(422)
            ->assertSee(PrikazPredloga::GRESKA_NAPOMENA_DUGACKA)
            ->assertSee(self::LINK);
        $this->assertSame(0, Predlog::query()->count());

        // Ogledalo: tačno petsto slova sa kvačicama (hiljadu bajtova) prolazi.
        $this->posalji(['link' => self::LINK, 'napomena' => str_repeat('č', 500)])->assertRedirect(route('predlozi.forma'));
        $this->assertSame(500, mb_strlen((string) Predlog::query()->sole()->napomena));
    }

    #[Test]
    public function link_ima_najvise_dve_hiljade_cetrdeset_osam_znakova(): void
    {
        $tacno = 'https://primer.rs/'.str_repeat('a', 2048 - 18);
        $this->assertSame(2048, strlen($tacno));

        $this->posalji(['link' => $tacno.'a'])->assertStatus(422)->assertSee(PrikazPredloga::GRESKA_LINK_DUGACAK);
        $this->assertSame(0, Predlog::query()->count());

        // Ogledalo: tačno 2048 znakova prolazi.
        $this->posalji(['link' => $tacno])->assertRedirect(route('predlozi.forma'));
        $this->assertSame($tacno, Predlog::query()->sole()->link);
    }

    // Privatnost: tabela nema nijednu ličnu kolonu, a ni dopunska polja ni adresa pošiljaoca ne ulaze u zapis.
    #[Test]
    public function predlog_nema_licnih_kolona_i_ne_cuva_ni_ime_ni_mejl_ni_telefon_ni_adresu(): void
    {
        $this->assertSame(['id', 'link', 'napomena', 'status', 'created_at', 'updated_at'], Schema::getColumnListing('predlozi'));

        $this->posalji(['link' => self::LINK, 'ime' => 'Pera Perić', 'email' => 'pera@primer.rs', 'telefon' => '064 123 456'], '203.0.113.77')->assertRedirect(route('predlozi.forma'));

        $zapis = json_encode(Predlog::query()->sole()->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertCount(6, Predlog::query()->sole()->getAttributes());
        foreach (['Pera', 'pera@primer.rs', '064 123 456', '203.0.113.77'] as $licno) {
            $this->assertStringNotContainsString($licno, (string) $zapis);
        }
    }

    // Predlog nije prilika: ne pravi nacrt, ne ide u obradu uvoza i ne vidi se na sajtu.
    #[Test]
    public function predlog_nije_prilika_i_ne_pravi_nacrt_ni_stavku_kalendara(): void
    {
        $this->posalji(['link' => self::LINK, 'napomena' => 'Konkurs za stipendije']);

        $this->assertSame(1, Predlog::query()->count());
        $this->assertSame(0, Prilika::query()->count());
        $this->assertSame(0, KalendarStavka::query()->count());

        $this->artisan('uvoz:obradi')->expectsOutput('Nema nacrta iz uvoza za obradu.')->assertExitCode(0);
        $this->get(route('prilike.index'))->assertDontSee(self::LINK)->assertDontSee('Konkurs za stipendije');
    }

    #[Test]
    public function slanje_je_ograniceno_po_adresi_a_cetvrti_predlog_u_minutu_dobija_poruku(): void
    {
        $this->assertSame(3, config('predlozi.slanja_u_minuti'));

        foreach (range(1, 3) as $broj) {
            $this->posalji(['link' => self::LINK.'/'.$broj])->assertRedirect(route('predlozi.forma'));
        }

        $this->posalji(['link' => self::LINK.'/4', 'napomena' => 'Četvrti'])
            ->assertStatus(429)
            ->assertSee(PrikazPredloga::PREVISE_PREDLOGA)
            ->assertSee(self::LINK.'/4', false)
            ->assertSee('Četvrti');
        $this->assertSame(3, Predlog::query()->count());

        // Ogledalo: druga adresa i dalje šalje, a otvaranje forme se ne ograničava.
        $this->posalji(['link' => self::LINK.'/5'], '198.51.100.8')->assertRedirect(route('predlozi.forma'));
        $this->get(route('predlozi.forma'))->assertOk();
        $this->assertSame(4, Predlog::query()->count());
    }

    #[Test]
    public function granica_slanja_dolazi_iz_podesavanja(): void
    {
        config(['predlozi.slanja_u_minuti' => 1]);

        $this->posalji(['link' => self::LINK.'/1'])->assertRedirect(route('predlozi.forma'));
        $this->posalji(['link' => self::LINK.'/2'])->assertStatus(429);
        $this->assertSame(1, Predlog::query()->count());
    }

    // Veza je na strani „Prilike", i kad nema nijedne prilike, i kad ih ima; ne i na drugim stranama (podnožje je paket 80).
    #[Test]
    public function strana_prilike_vodi_na_formu_i_prazna_i_puna(): void
    {
        $veza = 'href="'.route('predlozi.forma').'"';

        $this->get(route('prilike.index'))->assertOk()->assertSee(PrikazPredloga::VEZA_SA_PRILIKA)->assertSee($veza, false);

        Prilika::factory()->objavljena()->create();
        $this->get(route('prilike.index'))->assertOk()->assertSee(PrikazPredloga::VEZA_SA_PRILIKA)->assertSee($veza, false);

        $this->get(route('organizacije.index'))->assertOk()->assertDontSee($veza, false);
    }
}
