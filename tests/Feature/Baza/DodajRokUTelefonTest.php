<?php

namespace Tests\Feature\Baza;

use App\Models\Prilika;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 49: „Dodaj rok u telefon" skida mali kalendar fajl (.ics) sa naslovom, rokom na ceo dan i adresom naše strane; samo javne prilike sa rokom.
#[Group('baza')]
class DodajRokUTelefonTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...['naslov' => 'Stipendija za studente', 'rok' => '2026-12-15'], ...$dopuna]);
    }

    /** Redovi fajla kakvi su posle spajanja prelomljenih (RFC 5545), bez praznog poslednjeg. */
    private function redovi(string $telo): array
    {
        return explode("\r\n", rtrim(preg_replace('/\r\n /', '', $telo) ?? '', "\r\n"));
    }

    private function fajl(Prilika $prilika): string
    {
        return $this->get(route('prilike.rok', $prilika->slug))->assertOk()->getContent();
    }

    #[Test]
    public function fajl_nosi_naslov_rok_na_ceo_dan_i_adresu_nase_strane(): void
    {
        $prilika = $this->prilika();

        $odgovor = $this->get(route('prilike.rok', $prilika->slug))->assertOk();
        $telo = $odgovor->getContent();
        $adresa = route('prilike.show', $prilika->slug);

        $this->assertSame('text/calendar; charset=utf-8', $odgovor->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="rok-'.$prilika->slug.'.ics"', $odgovor->headers->get('Content-Disposition'));
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $telo);
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", "\n", $telo));
        $this->assertSame([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Putardo Vudar//Rok prilike//SR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:prilika-'.$prilika->id.'-20261215@'.parse_url($adresa, PHP_URL_HOST),
            'DTSTAMP:20261010T100000Z',
            'DTSTART;VALUE=DATE:20261215',
            'DTEND;VALUE=DATE:20261216',
            'SUMMARY:Rok: Stipendija za studente',
            'DESCRIPTION:Stipendija za studente\n'.$adresa,
            'URL:'.$adresa,
            'END:VEVENT', 'END:VCALENDAR',
        ], $this->redovi($telo));
    }

    /** @return array<string, array{0: string, 1: string}> rok, sutradan */
    public static function krajeviMeseca(): array
    {
        return ['kraj godine' => ['2026-12-31', '20270101'], 'prestupna godina' => ['2028-02-29', '20280301'], 'običan dan' => ['2026-10-31', '20261101'], 'današnji dan' => ['2026-10-10', '20261011']];
    }

    #[Test]
    #[DataProvider('krajeviMeseca')]
    public function dan_posle_roka_je_kraj_dogadjaja_na_ceo_dan(string $rok, string $sutradan): void
    {
        $redovi = $this->redovi($this->fajl($this->prilika(['rok' => $rok])));

        $this->assertContains('DTSTART;VALUE=DATE:'.str_replace('-', '', $rok), $redovi);
        $this->assertContains('DTEND;VALUE=DATE:'.$sutradan, $redovi);
    }

    // Znaci koji u kalendar fajlu nešto znače (zarez, tačka-zarez, kosa crta, novi red) se pišu sa kosom crtom; ništa ne ostaje golo.
    #[Test]
    public function znaci_u_naslovu_se_pisu_po_pravilu_fajla(): void
    {
        $prilika = $this->prilika(['naslov' => 'Posao, praksa; obuka \\ a "novi"']);

        $redovi = $this->redovi($this->fajl($prilika));

        $this->assertContains('SUMMARY:Rok: Posao\, praksa\; obuka \\\\ a "novi"', $redovi);
        $this->assertContains('DESCRIPTION:Posao\, praksa\; obuka \\\\ a "novi"\n'.route('prilike.show', $prilika->slug), $redovi);
    }

    // Dug red se prelama na 75 bajtova (sledeći red počinje razmakom), znak sa dva bajta se ne seče, a spojen red je isti kao nepreloman.
    #[Test]
    public function dug_naslov_se_prelama_bez_secenja_znaka(): void
    {
        $naslov = str_repeat('Šćepan učitelj žetva đak ', 8);
        $prilika = $this->prilika(['naslov' => trim($naslov)]);

        $telo = $this->fajl($prilika);
        $fizicki = explode("\r\n", rtrim($telo, "\r\n"));

        $duzi = array_filter($fizicki, fn (string $red) => strlen($red) > 75);
        $prelomljeni = array_filter($fizicki, fn (string $red) => str_starts_with($red, ' '));

        $this->assertSame([], $duzi);
        $this->assertNotEmpty($prelomljeni, 'merilo mora da obiđe prelomljene redove');
        foreach ($fizicki as $red) {
            $this->assertTrue(mb_check_encoding($red, 'UTF-8'), $red);
        }
        $this->assertContains('SUMMARY:Rok: '.trim($naslov), $this->redovi($telo));
    }

    #[Test]
    public function dugme_je_na_strani_prilike_sa_rokom_i_vodi_na_fajl(): void
    {
        $prilika = $this->prilika();

        $html = $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<a class="dugme-veza" href="'.route('prilike.rok', $prilika->slug).'">'.PrikazPrilike::DODAJ_ROK.'</a>', $html);
        // Ogledalo: spisak ga nema.
        $this->assertStringNotContainsString(PrikazPrilike::DODAJ_ROK, $this->get(route('prilike.index'))->getContent());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function bezRokaKojiVazi(): array
    {
        return [
            'bez roka' => [['rok' => null]],
            'stalno otvorena' => [['rok' => '2026-12-15', 'rok_stalno_otvoren' => true]],
            'stalno otvorena bez roka' => [['rok' => null, 'rok_stalno_otvoren' => true]],
        ];
    }

    // Bez roka i za stalno otvorene nema dugmeta, a adresa fajla daje 404 (nema šta da se skine).
    #[Test]
    #[DataProvider('bezRokaKojiVazi')]
    public function bez_roka_koji_vazi_nema_dugmeta_ni_fajla(array $dopuna): void
    {
        $prilika = $this->prilika($dopuna);

        $html = $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString(PrikazPrilike::DODAJ_ROK, $html);
        $this->assertStringNotContainsString('rok.ics', $html);
        $this->get(route('prilike.rok', $prilika->slug))->assertNotFound();
    }

    // Samo javne prilike: nacrt, arhivirana i prilika kojoj je rok prošao daju 404 bez ijednog podatka; ogledalo: javna daje fajl.
    #[Test]
    public function nejavne_prilike_daju_404_a_javna_fajl(): void
    {
        $nacrt = Prilika::factory()->create(['naslov' => 'Tajni nacrt', 'rok' => '2026-12-15']);
        $arhivirana = Prilika::factory()->arhivirana()->create(['naslov' => 'Tajna arhiva', 'rok' => '2026-12-15']);
        $istekla = $this->prilika(['naslov' => 'Tajna istekla', 'rok' => '2026-10-09']);
        $javna = $this->prilika(['naslov' => 'Javna prilika']);

        foreach ([$nacrt, $arhivirana, $istekla] as $nejavna) {
            $odgovor = $this->get(route('prilike.rok', $nejavna->slug))->assertNotFound();

            $this->assertStringNotContainsString($nejavna->naslov, (string) $odgovor->getContent());
            $this->assertNotSame('text/calendar; charset=utf-8', $odgovor->headers->get('Content-Type'));
        }

        $this->assertContains('SUMMARY:Rok: Javna prilika', $this->redovi($this->fajl($javna)));
        $this->get('/prilike/nepostojeca/rok.ics')->assertNotFound();
    }
}
