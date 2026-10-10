<?php

namespace Tests\Feature\Baza;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\PrikazPrilike;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\VidljivTekst;

// Paket 61 T3: na /prilike je pored izbora vrste i izbor „Zatvara se uskoro"; radi zajedno sa vrstom i pretragom, kao obična veza i obična forma, bez skripta.
#[Group('baza')]
class ZatvaraSeUskoroSpisakTest extends BazaTestCase
{
    use RefreshDatabase, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));

        $this->prilika('Posao uskoro', '2026-10-12', VrstaPrilike::Posao, 'Skladište u Nišu.');
        $this->prilika('Praksa uskoro', '2026-10-20', VrstaPrilike::Praksa, 'Praksa u Nišu.');
        $this->prilika('Praksa u Beogradu uskoro', '2026-10-24', VrstaPrilike::Praksa, 'Praksa u Beogradu.');
        $this->prilika('Posao kasno', '2026-12-01', VrstaPrilike::Posao, 'Skladište u Nišu.');
        $this->prilika('Posao stalno', null, VrstaPrilike::Posao, 'Stalno otvoreno.', ['rok_stalno_otvoren' => true]);
        $this->prilika('Posao nacrt', '2026-10-15', VrstaPrilike::Posao, 'Nacrt.', ['status' => StatusPrilike::Nacrt]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function prilika(string $naslov, ?string $rok, VrstaPrilike $vrsta, string $opis, array $dopuna = []): Prilika
    {
        return Prilika::factory()->objavljena()->create([...['naslov' => $naslov, 'rok' => $rok, 'vrsta' => $vrsta, 'kratak_opis' => $opis], ...$dopuna]);
    }

    /** @return list<string> */
    private function naslovi(string $html): array
    {
        preg_match_all('#<article class="kartica redosled">\s*<h2><a [^>]*>([^<]+)</a></h2>#', $html, $nalazi);

        return $nalazi[1];
    }

    /** @return list<array{0: string, 1: string, 2: bool, 3: bool}> naziv, adresa, klasa „aktivno", aria-current */
    private function dugmad(string $html): array
    {
        preg_match('#<nav class="filteri"[^>]*>(.*?)</nav>#s', $html, $filteri);
        preg_match_all('#<a class="filter-pilula( aktivno)?" href="([^"]+)"( aria-current="true")?>([^<]+)</a>#', $filteri[1] ?? '', $nalazi, PREG_SET_ORDER);

        return array_map(fn (array $dugme) => [$dugme[4], html_entity_decode($dugme[2]), $dugme[1] !== '', $dugme[3] !== ''], $nalazi);
    }

    /** @param  array<string, string>  $upit */
    private function spisak(array $upit = []): string
    {
        return $this->get(route('prilike.index', $upit))->assertOk()->getContent();
    }

    /** @return list<array{0: string, 1: string, 2: bool, 3: bool}> */
    private function pilulaUskoro(string $html): array
    {
        return array_values(array_filter($this->dugmad($html), fn (array $d) => $d[0] === PrikazPrilike::USKORO));
    }

    #[Test]
    public function uskoro_pokazuje_samo_javne_prilike_sa_rokom_do_cetrnaest_dana_najblizi_prvi(): void
    {
        $this->assertSame(['Posao uskoro', 'Praksa uskoro', 'Praksa u Beogradu uskoro'], $this->naslovi($this->spisak(['uskoro' => '1'])));
        // Ogledalo: bez izbora su sve javne prilike, i one daleke i stalno otvorene.
        $this->assertSame(['Posao uskoro', 'Praksa uskoro', 'Praksa u Beogradu uskoro', 'Posao kasno', 'Posao stalno'], $this->naslovi($this->spisak()));
    }

    #[Test]
    public function uskoro_radi_zajedno_sa_vrstom(): void
    {
        $this->assertSame(['Posao uskoro'], $this->naslovi($this->spisak(['vrsta' => 'posao', 'uskoro' => '1'])));
        $this->assertSame(['Praksa uskoro', 'Praksa u Beogradu uskoro'], $this->naslovi($this->spisak(['vrsta' => 'praksa', 'uskoro' => '1'])));
        // Ogledalo: sama vrsta daje i daleke i stalno otvorene te vrste.
        $this->assertSame(['Posao uskoro', 'Posao kasno', 'Posao stalno'], $this->naslovi($this->spisak(['vrsta' => 'posao'])));
    }

    #[Test]
    public function uskoro_radi_zajedno_sa_pretragom_i_vrstom(): void
    {
        $this->assertSame(['Posao uskoro', 'Praksa uskoro'], $this->naslovi($this->spisak(['q' => 'nisu', 'uskoro' => '1'])));
        $this->assertSame(['Praksa u Beogradu uskoro'], $this->naslovi($this->spisak(['q' => 'beogradu', 'uskoro' => '1'])));
        $this->assertSame(['Praksa uskoro'], $this->naslovi($this->spisak(['q' => 'nisu', 'vrsta' => 'praksa', 'uskoro' => '1'])));
        // Ogledalo: ista pretraga bez izbora nalazi i daleku priliku iz Niša.
        $this->assertSame(['Posao uskoro', 'Praksa uskoro', 'Posao kasno'], $this->naslovi($this->spisak(['q' => 'nisu'])));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function vrednostiKojeNisuJedan(): array
    {
        return ['nula' => [['uskoro' => '0']], 'tekst' => [['uskoro' => 'da']], 'true' => [['uskoro' => 'true']], 'prazno' => [['uskoro' => '']], 'niz' => [['uskoro' => ['1']]]];
    }

    /** @param  array<string, mixed>  $upit */
    #[Test]
    #[DataProvider('vrednostiKojeNisuJedan')]
    public function samo_uskoro_jedan_ukljucuje_izbor(array $upit): void
    {
        $html = $this->get(route('prilike.index', $upit))->assertOk()->getContent();

        $this->assertCount(5, $this->naslovi($html));
        $this->assertFalse($this->pilulaUskoro($html)[0][2]);
    }

    #[Test]
    public function izbor_je_obicna_veza_posle_vrsta_i_aktivan_samo_kad_je_ukljucen(): void
    {
        $iskljucen = $this->dugmad($this->spisak());
        $ukljucen = $this->dugmad($this->spisak(['uskoro' => '1']));

        $this->assertSame([PrikazPrilike::SVE_PRILIKE, 'Posao', 'Praksa', PrikazPrilike::USKORO], array_column($iskljucen, 0));
        $this->assertSame(route('prilike.index', ['uskoro' => '1']), $iskljucen[3][1]);
        $this->assertFalse($iskljucen[3][2]);
        $this->assertFalse($iskljucen[3][3]);
        $this->assertTrue($ukljucen[3][2]);
        $this->assertTrue($ukljucen[3][3]);
        // Isključuje se istim izborom: veza vodi na spisak bez „uskoro".
        $this->assertSame(route('prilike.index'), $ukljucen[3][1]);
        // „Sve prilike" je aktivno samo kad nema ni vrste ni „uskoro".
        $this->assertTrue($iskljucen[0][2]);
        $this->assertFalse($ukljucen[0][2]);
        $this->assertFalse($ukljucen[0][3]);
        // Bez skripta: obična veza, bez dugmeta, forme i rukovaoca događaja.
        preg_match('#<nav class="filteri"[^>]*>(.*?)</nav>#s', $this->spisak(['uskoro' => '1']), $filteri);
        $this->assertStringNotContainsString('<button', $filteri[1]);
        $this->assertStringNotContainsString('onclick', $filteri[1]);
        $this->assertStringNotContainsString('<script', $filteri[1]);
    }

    #[Test]
    public function veze_cuvaju_ono_sto_je_vec_izabrano(): void
    {
        $dugmad = $this->dugmad($this->spisak(['vrsta' => 'praksa', 'q' => 'nisu', 'uskoro' => '1']));
        $po = array_column($dugmad, null, 0);

        // Vrste čuvaju „uskoro" i pretragu; „uskoro" čuva vrstu i pretragu; „Sve prilike" čuva samo pretragu.
        $this->assertSame(route('prilike.index', ['vrsta' => 'posao', 'q' => 'nisu', 'uskoro' => '1']), $po['Posao'][1]);
        $this->assertSame(route('prilike.index', ['vrsta' => 'praksa', 'q' => 'nisu']), $po[PrikazPrilike::USKORO][1]);
        $this->assertSame(route('prilike.index', ['q' => 'nisu']), $po[PrikazPrilike::SVE_PRILIKE][1]);
        $this->assertTrue($po['Praksa'][2]);
        $this->assertTrue($po[PrikazPrilike::USKORO][2]);
        // Ogledalo: isključeno „uskoro" se uključuje uz istu vrstu i pretragu.
        $iskljucen = array_column($this->dugmad($this->spisak(['vrsta' => 'praksa', 'q' => 'nisu'])), null, 0);
        $this->assertSame(route('prilike.index', ['vrsta' => 'praksa', 'q' => 'nisu', 'uskoro' => '1']), $iskljucen[PrikazPrilike::USKORO][1]);
        $this->assertSame(route('prilike.index', ['vrsta' => 'posao', 'q' => 'nisu']), $iskljucen['Posao'][1]);
    }

    #[Test]
    public function izbora_nema_kad_nijedna_javna_prilika_ne_vodi_do_njega_osim_kad_je_ukljucen(): void
    {
        Prilika::query()->whereIn('naslov', ['Posao uskoro', 'Praksa uskoro', 'Praksa u Beogradu uskoro'])->update(['rok' => '2027-01-15']);

        $this->assertSame([], $this->pilulaUskoro($this->spisak()));
        // Uključen ostaje vidljiv, da se može isključiti, a poruka kaže zašto je spisak prazan.
        $html = $this->spisak(['uskoro' => '1']);

        $this->assertCount(1, $this->pilulaUskoro($html));
        $this->assertTrue($this->pilulaUskoro($html)[0][2]);
        $this->assertSame([], $this->naslovi($html));
        $this->assertStringContainsString(PrikazPrilike::NEMA_USKORO, $this->vidljivTekst($html));
        $this->assertStringNotContainsString(PrikazPrilike::NEMA_PRILIKA, $this->vidljivTekst($html));
    }

    #[Test]
    public function pretraga_bez_rezultata_uz_uskoro_pise_da_nema_rezultata(): void
    {
        $tekst = $this->vidljivTekst($this->spisak(['q' => 'astronomija', 'uskoro' => '1']));

        $this->assertStringContainsString(PrikazPrilike::NEMA_REZULTATA, $tekst);
        $this->assertStringNotContainsString(PrikazPrilike::NEMA_USKORO, $tekst);
    }

    // Forma pretrage nosi i izbor „uskoro" (uz vrstu), pa pretraga ostaje unutar njega; bez izbora nema skrivenog polja.
    #[Test]
    public function forma_pretrage_nosi_uskoro_i_vrstu_kad_su_izabrani(): void
    {
        $sa = $this->spisak(['vrsta' => 'praksa', 'uskoro' => '1']);
        $bez = $this->spisak(['vrsta' => 'praksa']);

        $this->assertStringContainsString('<input type="hidden" name="uskoro" value="1">', $sa);
        $this->assertStringContainsString('<input type="hidden" name="vrsta" value="praksa">', $sa);
        $this->assertStringNotContainsString('name="uskoro"', $bez);
        $this->assertStringContainsString('<input type="hidden" name="vrsta" value="praksa">', $bez);
        $this->assertStringNotContainsString('name="uskoro"', $this->get(route('pocetna'))->getContent());
    }

    // Izbor postoji samo na spisku prilika; ostale strane ga nemaju.
    #[Test]
    public function izbor_postoji_samo_na_spisku(): void
    {
        $prilika = Prilika::query()->where('naslov', 'Posao uskoro')->firstOrFail();

        foreach ([route('prilike.show', $prilika->slug), route('vodici.index'), route('organizacije.index'), route('pomocnik.index'), route('kalendar.index')] as $adresa) {
            $this->assertStringNotContainsString('class="filteri"', $this->get($adresa)->getContent(), $adresa);
        }
    }
}
