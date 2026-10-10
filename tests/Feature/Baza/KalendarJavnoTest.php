<?php

namespace Tests\Feature\Baza;

use App\Enums\Grupa;
use App\Models\KalendarStavka;
use App\Models\Prilika;
use App\Support\PrikazKalendara;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 44: /kalendar na sajtu. Samo ono što je stvarno bilo (objavljeno, a rok već prošao), po mesecu roka, 12 meseci od tekućeg.
#[Group('baza')]
class KalendarJavnoTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @param  array<string, mixed>  $dopuna */
    private function stavka(string $naziv, string $do, array $dopuna = []): KalendarStavka
    {
        return KalendarStavka::factory()->objavljena()->create([...['naziv' => $naziv, 'otvoreno_do' => $do], ...$dopuna]);
    }

    private function strana(): string
    {
        return (string) $this->get(route('kalendar.index'))->assertOk()->getContent();
    }

    /** @return list<string> */
    private function meseci(string $html): array
    {
        preg_match_all('#<h2 class="naslov-izvora">([^<]+)</h2>#', $html, $nalazi);

        return $nalazi[1];
    }

    /** @return list<string> */
    private function naslovi(string $html): array
    {
        preg_match_all('#<article class="kartica">\s*<h3>([^<]+)</h3>#', $html, $nalazi);

        return $nalazi[1];
    }

    // „Danas" je po beogradskom vremenu, a ne po UTC: posle ponoći u Beogradu stavka završena juče je javna, a pre ponoći ona koja
    // se završava danas nije.
    #[Test]
    public function danas_se_racuna_po_beogradskom_vremenu_oko_ponoci(): void
    {
        $this->stavka('Juče', '2026-10-09');
        $this->stavka('Danas', '2026-10-10');

        $this->travelTo(Carbon::create(2026, 10, 10, 0, 30, 0, 'Europe/Belgrade'));
        $this->assertSame(['Juče'], $this->naslovi($this->strana()));

        $this->travelTo(Carbon::create(2026, 10, 9, 23, 30, 0, 'Europe/Belgrade'));
        $this->assertSame([], $this->naslovi($this->strana()));

        $this->travelTo(Carbon::create(2026, 10, 11, 0, 30, 0, 'Europe/Belgrade'));
        $this->assertEqualsCanonicalizing(['Juče', 'Danas'], $this->naslovi($this->strana()));
    }

    #[Test]
    public function nacrt_nije_javan_a_objavljena_stavka_jeste(): void
    {
        $this->stavka('Objavljena', '2026-01-26');
        KalendarStavka::factory()->create(['naziv' => 'Nacrt', 'otvoreno_do' => '2026-01-26']);

        $html = $this->strana();

        $this->assertSame(['Objavljena'], $this->naslovi($html));
        $this->assertSame(['Objavljena'], KalendarStavka::javne()->pluck('naziv')->all());
        $this->assertSame(2, KalendarStavka::query()->count());
    }

    // Ništa se ne predviđa: stavka čiji kraj je danas ili kasnije bila bi najava, pa je nema.
    #[Test]
    public function stavka_ciji_je_kraj_danas_ili_kasnije_se_ne_pise(): void
    {
        $this->stavka('Juče', '2026-10-09');
        $this->stavka('Danas', '2026-10-10');
        $this->stavka('Sutra', '2026-10-11');
        $this->stavka('Za godinu', '2027-10-10');

        $this->assertSame(['Juče'], $this->naslovi($this->strana()));
    }

    // Dvanaest meseci od tekućeg: oktobar, novembar, … septembar; meseci bez stavki se ne crtaju.
    #[Test]
    public function meseci_idu_od_tekuceg_i_samo_oni_sa_stavkama(): void
    {
        $this->stavka('Januar 2026', '2026-01-26');
        $this->stavka('Oktobar', '2026-10-05');
        $this->stavka('Decembar', '2025-12-15');
        $this->stavka('Septembar', '2026-09-03');
        $this->stavka('Novembar', '2025-11-02');

        $this->assertSame(['Oktobar', 'Novembar', 'Decembar', 'Januar', 'Septembar'], $this->meseci($this->strana()));
    }

    // Isti podaci, drugi „danas": red meseci počinje od tekućeg meseca, a ne od januara.
    #[Test]
    public function red_meseci_pocinje_od_tekuceg_meseca(): void
    {
        foreach ([1 => 'Januar', 4 => 'April', 8 => 'Avgust', 12 => 'Decembar'] as $mesec => $naziv) {
            $this->stavka($naziv, sprintf('2026-%02d-05', $mesec));
        }

        $this->travelTo(Carbon::create(2027, 3, 20, 12, 0, 0, 'Europe/Belgrade'));
        $this->assertSame(['April', 'Avgust', 'Decembar', 'Januar'], $this->meseci($this->strana()));

        $this->travelTo(Carbon::create(2026, 12, 31, 12, 0, 0, 'Europe/Belgrade'));
        $this->assertSame(['Decembar', 'Januar', 'April', 'Avgust'], $this->meseci($this->strana()));
    }

    // Stavka stoji u mesecu svog roka, ma koje je godine bila; unutar meseca po danu.
    #[Test]
    public function stavka_stoji_u_mesecu_roka_ma_koje_godine_a_unutar_meseca_po_danu(): void
    {
        $this->stavka('Kraj meseca', '2026-01-28');
        $this->stavka('Početak meseca', '2025-01-03');
        $this->stavka('Sredina meseca', '2026-01-15');

        $html = $this->strana();

        $this->assertSame(['Januar'], $this->meseci($html));
        $this->assertSame(['Početak meseca', 'Sredina meseca', 'Kraj meseca'], $this->naslovi($html));
    }

    #[Test]
    public function kartica_pise_prosli_put_ko_daje_grupe_napomenu_i_zvanicni_izvor(): void
    {
        $this->stavka('Stipendija Dositeja', '2026-01-26', [
            'ko_daje' => 'Fond za mlade talente', 'napomena' => 'Prijave su išle preko eUprave.', 'link_izvora' => 'https://fondzamladetalente.gov.rs/dositeja/',
            'grupe' => ['studenti', 'master-doktorski'],
        ]);

        $html = $this->strana();
        $tekst = $this->vidljivTekst($html);

        $this->assertStringContainsString('Prošli put: do 26.01.2026.', $tekst);
        $this->assertStringContainsString('Fond za mlade talente', $tekst);
        $this->assertStringContainsString('Kome je namenjeno: Studenti, Master i doktorski studenti', $tekst);
        $this->assertStringContainsString('Prijave su išle preko eUprave.', $tekst);
        $this->assertStringContainsString('Zvanični izvor: ', $tekst);
        $this->assertStringContainsString('<a href="https://fondzamladetalente.gov.rs/dositeja/" rel="noopener noreferrer nofollow" target="_blank">fondzamladetalente.gov.rs</a>', $html);
    }

    #[Test]
    public function kad_se_zna_i_pocetak_pise_od_do(): void
    {
        $this->stavka('Sa početkom', '2026-01-26', ['otvoreno_od' => '2025-12-01']);
        $this->stavka('Bez početka', '2026-01-27');

        $tekst = $this->vidljivTekst($this->strana());

        $this->assertStringContainsString('Prošli put: od 01.12.2025. do 26.01.2026.', $tekst);
        $this->assertStringContainsString('Prošli put: do 27.01.2026.', $tekst);
    }

    // Ogledalo: stavka bez ko daje, grupa, napomene i linka piše samo naslov i „Prošli put".
    #[Test]
    public function prazna_polja_ne_crtaju_redove(): void
    {
        KalendarStavka::factory()->objavljena()->create(['naziv' => 'Gola stavka', 'otvoreno_do' => '2026-01-26', 'ko_daje' => null, 'grupe' => null, 'napomena' => null, 'link_izvora' => 'https://a.rs/']);
        KalendarStavka::query()->update(['link_izvora' => null]);

        $tekst = $this->vidljivTekst($this->strana());

        $this->assertStringContainsString('Gola stavka', $tekst);
        $this->assertStringContainsString('Prošli put: do 26.01.2026.', $tekst);
        $this->assertStringNotContainsString('Kome je namenjeno', $tekst);
        $this->assertStringNotContainsString('Zvanični izvor', $tekst);
        $this->assertStringNotContainsString('Sada otvoreno', $tekst);
    }

    // Link koji nije veb adresa (stigao mimo admina) se ne ispisuje kao veza.
    #[Test]
    public function link_koji_nije_veb_adresa_se_ne_ispisuje(): void
    {
        $stavka = $this->stavka('Loš link', '2026-01-26');
        KalendarStavka::query()->whereKey($stavka->getKey())->update(['link_izvora' => 'javascript:alert(1)']);

        $html = $this->strana();

        $this->assertStringContainsString('Loš link', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    // Ista stvar je sada otvorena kad javna prilika ima isti link izvora; tada kartica vodi i na priliku.
    #[Test]
    public function kartica_vodi_na_priliku_koja_je_sada_otvorena_sa_istim_linkom(): void
    {
        $this->stavka('Dositeja prošle godine', '2026-01-26', ['link_izvora' => 'https://fond.rs/dositeja']);
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => 'Dositeja ove godine', 'link_izvora' => 'https://fond.rs/dositeja/', 'rok' => '2026-12-31']);

        $html = $this->strana();

        $this->assertStringContainsString('Sada otvoreno: ', $this->vidljivTekst($html));
        $this->assertSame('Sada otvoreno: ', PrikazKalendara::SADA_OTVORENO);
        $this->assertStringContainsString('<a href="'.route('prilike.show', $prilika->slug).'">Dositeja ove godine</a>', $html);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function prilikeKojeNisuOtvorene(): array
    {
        return [
            'drugi link' => [['link_izvora' => 'https://fond.rs/druga-strana', 'status' => 'objavljeno', 'rok' => '2026-12-31']],
            'nacrt sa istim linkom' => [['link_izvora' => 'https://fond.rs/dositeja', 'status' => 'nacrt', 'rok' => '2026-12-31']],
            'arhivirana sa istim linkom' => [['link_izvora' => 'https://fond.rs/dositeja', 'status' => 'arhivirano', 'rok' => '2026-12-31']],
            'isteklo sa istim linkom' => [['link_izvora' => 'https://fond.rs/dositeja', 'status' => 'objavljeno', 'rok' => '2026-10-01']],
        ];
    }

    // Ogledalo: drugi link, nejavna prilika i istekla prilika ne znače „sada otvoreno".
    /** @param  array<string, mixed>  $prilika */
    #[Test]
    #[DataProvider('prilikeKojeNisuOtvorene')]
    public function prilika_koja_nije_javna_ili_ima_drugi_link_ne_znaci_sada_otvoreno(array $prilika): void
    {
        $this->stavka('Dositeja', '2026-01-26', ['link_izvora' => 'https://fond.rs/dositeja']);
        Prilika::factory()->create([...['naslov' => 'Druga prilika'], ...$prilika]);

        $html = $this->strana();

        $this->assertStringContainsString('Dositeja', $html);
        $this->assertStringNotContainsString('Sada otvoreno', $this->vidljivTekst($html));
        $this->assertStringNotContainsString('Druga prilika', $html);
    }

    #[Test]
    public function bez_stavki_pise_da_je_kalendar_prazan(): void
    {
        KalendarStavka::factory()->create(['naziv' => 'Samo nacrt', 'otvoreno_do' => '2026-01-26']);

        $html = $this->strana();

        $this->assertStringContainsString('Kalendar je za sada prazan.', $this->vidljivTekst($html));
        $this->assertSame('Kalendar je za sada prazan.', PrikazKalendara::NEMA_STAVKI);
        $this->assertSame([], $this->meseci($html));
        $this->assertSame([], $this->naslovi($html));
    }

    // Nikad najava: na strani nema „otvoriće se", a svaka kartica kaže samo „Prošli put".
    #[Test]
    public function strana_nikad_ne_najavljuje(): void
    {
        $this->stavka('Prva', '2026-01-26');
        $this->stavka('Druga', '2026-03-10');

        $tekst = $this->vidljivTekst($this->strana());

        $this->assertSame(2, substr_count($tekst, 'Prošli put:'));
        $this->assertDoesNotMatchRegularExpression('/otvori(ti|ć|c)e?|otvara se|biće otvoren|očekuje se|će se/iu', $tekst);
        // Tekst uvoda je predlog iz paketa 44, pa je zapisan doslovno.
        $this->assertStringContainsString('Ovde piše samo šta je stvarno bilo otvoreno prošli put; ništa nije najava.', $tekst);
    }

    // Spisak su kartice, nikad tabela; naslov strane, odeljci po mesecu i naslovi kartica su h1, h2 i h3.
    #[Test]
    public function strana_je_kartice_sa_naslovom_mesecima_i_putanjom(): void
    {
        $this->stavka('Jedna', '2026-01-26');

        $html = $this->strana();

        $this->assertStringNotContainsString('<table', $html);
        $this->assertSame(1, substr_count($html, '<h1>'));
        $this->assertStringContainsString('<h1>Kalendar</h1>', $html);
        $this->assertStringContainsString('<title>Kalendar - ', $html);
        $this->assertSame(['Januar'], $this->meseci($html));
        $this->assertSame(['Jedna'], $this->naslovi($html));
    }

    // Mesec posle spiska kartica ima razmak odozgo (izmereno u pregledaču: bez njega se lepio uz poslednju karticu).
    #[Test]
    public function naslov_meseca_posle_spiska_ima_razmak_odozgo(): void
    {
        $this->assertMatchesRegularExpression('/\n\.spisak \+ \.naslov-izvora \{\s*margin-top: 30px;\s*\}/', $this->stilBezUpita());
    }

    #[Test]
    public function grupe_stavke_su_u_redosledu_enuma(): void
    {
        $this->stavka('Sa grupama', '2026-01-26', ['grupe' => [Grupa::Romi->value, Grupa::Osnovci->value]]);

        $this->assertStringContainsString('Kome je namenjeno: Osnovci, Romi i Romkinje', $this->vidljivTekst($this->strana()));
    }
}
