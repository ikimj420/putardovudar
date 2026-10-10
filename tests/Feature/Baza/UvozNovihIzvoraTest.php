<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 58: četiri nova RSS izvora (KOMS, Ministarstvo prosvete, Ministarstvo za ljudska i manjinska prava, Infostud) uvoze nacrte iz snimaka njihovih pravih odgovora;
// internet se ne koristi. Svaki izvor je u podešavanjima u jednom redu, bez novog čitača i bez novog formata.
#[Group('baza')]
class UvozNovihIzvoraTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(Carbon::create(2026, 10, 11, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<string>, 4: list<string|null>, 5: string}> ime, adresa, snimak, naslovi (latinicom), dani objave, početak prve veze */
    public static function izvori(): array
    {
        return [
            'KOMS' => [
                'KOMS - konkursi', 'https://koms.rs/category/konkursi/feed/', 'koms',
                ['Otvoramo poziv za međunarodnu konferenciju o lokalnim savetima mladih', 'Otvoren poziv za učešće na međunarodnom treningu: Dopiranje do mladih, promocija i mentorstvo', 'Poziv za 10. generaciju Akademije omladinske politike'],
                ['2026-10-02', '2026-09-23', '2026-09-01'], 'https://koms.rs/2026/',
            ],
            'Ministarstvo prosvete' => [
                'Ministarstvo prosvete', 'https://prosveta.gov.rs/kategorija/vazna-obavestenja/feed/', 'ministarstvo-prosvete',
                [
                    'Javni poziv za podnošenje prijava ustanova koje ostvaruju osnovno obrazovanje i vaspitanje u Republici Srbiji za dodelu finansijskih sredstava radi unapređenja uslova rada učeničkih zadruga',
                    'Javni poziv za podnošenje prijava ustanova koje ostvaruju srednje obrazovanje i vaspitanje u Republici Srbiji za dodelu finansijskih sredstava radi unapređenja uslova rada učeničkih zadruga',
                    'Javni poziv za podnošenje prijava gimnazija i srednjih škola koje imaju gimnazijska odeljenja za dodelu finansijskih sredstava u cilju unapređivanja uslova za ostvarivanje nastave i učenja',
                ],
                ['2026-10-09', '2026-10-02', '2026-10-02'], 'https://prosveta.gov.rs/vesti/',
            ],
            // Snimak je ćirilicom, i naslovi i opisi i datum objave („Тху, 01 Оцт 2026"): nacrti su latinicom, a dan objave se ne može pročitati, pa ostaje prazan.
            'Ministarstvo za ljudska i manjinska prava' => [
                'Ministarstvo za ljudska i manjinska prava', 'https://minljmpdd.gov.rs/category/konkursi-i-javni-pozivi-2026/feed/', 'ministarstvo-ljudska-prava',
                [
                    'Obaveštenje o produžetku roka za podnošenje prijava za Javni poziv organizacijama civilnog društva za predlaganje kandidata za članstvo u Komisiji za rešavanje pritužbi u sedištu Ministarstva unutrašnjih poslova',
                    'JAVNI KONKURS ZA POPUNJAVANJE IZVRŠILAČKIH RADNIH MESTA',
                    'Javni poziv organizacijama civilnog društva za predlaganje kandidata za članstvo u Komisiji za rešavanje pritužbi u sedištu Ministarstva unutrašnjih poslova',
                ],
                [null, null, null], 'https://minljmpdd.gov.rs/',
            ],
            'Infostud' => [
                'Infostud Poslovi', 'https://rss.infostud.com/poslovi/rss.php', 'infostud-poslovi',
                ['Roštilj majstor', 'Medicinska sestra/tehničar sa iskustvom', 'Specijalista za High Bay skladište'],
                ['2026-10-10', '2026-10-10', '2026-10-10'], 'http://poslovi.infostud.com/posao/',
            ],
        ];
    }

    private function snimak(string $snimak): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/rss/'.$snimak.'.xml'));
    }

    /**
     * @param  list<string>  $naslovi
     * @param  list<string|null>  $dani
     */
    #[Test]
    #[DataProvider('izvori')]
    public function izvor_je_u_podesavanjima_a_njegov_snimak_daje_tri_nacrta(string $ime, string $adresa, string $snimak, array $naslovi, array $dani, string $pocetakVeze): void
    {
        $this->assertContains(['ime' => $ime, 'adresa' => $adresa], config('uvoz.izvori'));
        config()->set('uvoz.izvori', [['ime' => $ime, 'adresa' => $adresa]]);
        Http::fake([$adresa => Http::response($this->snimak($snimak))]);

        $this->artisan('uvoz:rss')->expectsOutput($ime.': novih 3, preskočeno 0, greška: nema')->assertExitCode(0);

        $prilike = Prilika::query()->orderBy('id')->get();

        $this->assertSame($naslovi, $prilike->pluck('naslov')->all());
        $this->assertSame($dani, $prilike->map(fn (Prilika $prilika) => $prilika->izvor_objavljen?->toDateString())->all());

        foreach ($prilike as $prilika) {
            $this->assertSame(StatusPrilike::Nacrt, $prilika->status);
            $this->assertSame(VrstaPrilike::Drugo, $prilika->vrsta);
            $this->assertSame($ime, $prilika->naziv_izvora);
            $this->assertStringStartsWith($pocetakVeze, $prilika->link_izvora);
            $this->assertNotSame('', $prilika->kratak_opis);
            $this->assertLessThanOrEqual(300, mb_strlen($prilika->kratak_opis));
            $this->assertSame(0, preg_match('/[<>]/', $prilika->naslov.$prilika->kratak_opis), 'HTML u: '.$prilika->naslov);
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $prilika->naslov.$prilika->kratak_opis), 'Ćirilica u: '.$prilika->naslov);
            $this->assertNull($prilika->rok);
        }

        // Ništa se ne objavljuje: nijedna od uvezenih nije javna.
        $this->assertSame(0, Prilika::javne()->count());
        Http::assertSentCount(1);
    }

    // Ogledalo: drugo pokretanje nad istim snimkom ne pravi nijedan nacrt i ne menja postojeće.
    #[Test]
    #[DataProvider('izvori')]
    /**
     * @param  list<string>  $naslovi
     * @param  list<string|null>  $dani
     */
    public function drugo_pokretanje_ne_pravi_nista_novo(string $ime, string $adresa, string $snimak, array $naslovi, array $dani, string $pocetakVeze): void
    {
        config()->set('uvoz.izvori', [['ime' => $ime, 'adresa' => $adresa]]);
        Http::fake([$adresa => Http::response($this->snimak($snimak))]);

        $this->artisan('uvoz:rss');
        $this->assertSame($naslovi, Prilika::query()->orderBy('id')->pluck('naslov')->all());

        $this->artisan('uvoz:rss')->expectsOutput($ime.': novih 0, preskočeno 3, greška: nema');

        $this->assertSame(3, Prilika::query()->count());
    }

    // Snimak ministarstva je ćirilicom (neprazan skup za merilo), a ostala tri latinicom; nacrti su svi latinicom.
    #[Test]
    public function snimci_ministarstava_su_cirilicom_a_nacrti_iz_njih_latinicom(): void
    {
        foreach (['ministarstvo-prosvete', 'ministarstvo-ljudska-prava'] as $snimak) {
            $this->assertSame(1, preg_match('/\p{Cyrillic}/u', $this->snimak($snimak)), $snimak);
        }
        foreach (['koms', 'infostud-poslovi'] as $snimak) {
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', $this->snimak($snimak)), $snimak);
        }
        $this->assertStringContainsString('Тху, 01 Оцт 2026', $this->snimak('ministarstvo-ljudska-prava'));
    }

    // Izvor koji ne odgovara kao u pregledaču (drugi kod, prazno, nije RSS) ne ruši uvoz ostalih i ne pravi nacrte.
    #[Test]
    public function izvor_koji_ne_odgovara_ne_pravi_nacrte_a_ostali_rade(): void
    {
        $infostud = 'https://rss.infostud.com/poslovi/rss.php';
        $koms = 'https://koms.rs/category/konkursi/feed/';
        config()->set('uvoz.izvori', [['ime' => 'Infostud Poslovi', 'adresa' => $infostud], ['ime' => 'KOMS - konkursi', 'adresa' => $koms]]);
        Http::fake([$infostud => Http::response('Zabranjeno', 403), $koms => Http::response($this->snimak('koms'))]);

        $this->artisan('uvoz:rss')
            ->expectsOutput('Infostud Poslovi: novih 0, preskočeno 0, greška: izvor je odgovorio sa HTTP 403')
            ->expectsOutput('KOMS - konkursi: novih 3, preskočeno 0, greška: nema')
            ->assertExitCode(1);

        $this->assertSame(3, Prilika::query()->count());
        $this->assertSame(['KOMS - konkursi'], Prilika::query()->pluck('naziv_izvora')->unique()->values()->all());
    }
}
