<?php

namespace Tests\Feature\Baza;

use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\PrikazDeljenja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;
use Tests\Concerns\VidljivTekst;

// Paket 47: „Pošalji komšiji" na strani prilike, vodiča i organizacije: obične veze ka Viberu i WhatsAppu sa naslovom i adresom strane.
#[Group('baza')]
class PosaljiKomsijiTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana, VidljivTekst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 10, 12, 0, 0, 'Europe/Belgrade'));
    }

    /** @return array<string, array{0: string}> */
    public static function naslovi(): array
    {
        return [
            'običan' => ['Stipendija za studente'],
            'dijakritici i navodnici' => ['Stipendija „Dositeja“ za škole'],
            'znaci koji lome vezu' => ['Posao & praksa: 50% popusta? Da #1 = +2'],
            'razmaci i prelom reda' => ["Dva  razmaka\ni novi red"],
            'ćirilični znak' => ['Конкурс — 2027'],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> tip strane, naslov */
    private function strane(string $naslov): array
    {
        $prilika = Prilika::factory()->objavljena()->create(['naslov' => $naslov, 'rok' => '2026-12-15']);
        $vodic = Vodic::factory()->objavljen()->create(['naslov' => $naslov]);
        $organizacija = Organizacija::factory()->objavljena()->create(['naziv' => $naslov]);

        return [
            'prilika' => [route('prilike.show', $prilika->slug), $naslov],
            'vodič' => [route('vodici.show', $vodic->slug), $naslov],
            'organizacija' => [route('organizacije.show', $organizacija->slug), $naslov],
        ];
    }

    /** @return list<array{0: string, 1: string}> tekst veze, adresa veze (kako je u HTML-u, dekodirana iz atributa) */
    private function veze(string $html): array
    {
        preg_match('#<nav class="deljenje"[^>]*>(.*?)</nav>#s', $html, $red);
        preg_match_all('#<a class="dugme-veza" href="([^"]+)"[^>]*>([^<]+)</a>#', $red[1] ?? '', $nalazi, PREG_SET_ORDER);

        return array_map(fn (array $veza) => [$veza[2], html_entity_decode($veza[1])], $nalazi);
    }

    #[Test]
    #[DataProvider('naslovi')]
    public function veze_nose_tacan_naslov_i_adresu_pravilno_kodirane_na_sve_tri_strane(string $naslov): void
    {
        foreach ($this->strane($naslov) as $tip => [$adresa]) {
            $html = $this->get($adresa)->assertOk()->getContent();
            $veze = $this->veze($html);

            // Dokaz da je red obiđen: tačno dve veze, Viber pa WhatsApp.
            $this->assertSame([PrikazDeljenja::VIBER, PrikazDeljenja::WHATSAPP], array_column($veze, 0), $tip);
            [$viber, $whatsapp] = array_column($veze, 1);

            $this->assertStringStartsWith('viber://forward?text=', $viber, $tip);
            $this->assertStringStartsWith('https://wa.me/?text=', $whatsapp, $tip);

            foreach ([$viber, $whatsapp] as $veza) {
                $kodirano = substr($veza, (int) strpos($veza, 'text=') + 5);

                $this->assertSame($naslov."\n".$adresa, rawurldecode($kodirano), $tip.': '.$veza);
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_.~%]+$/', $kodirano, $tip.': u tekstu nema znaka koji lomi vezu');
            }
        }
    }

    // Ogledalo: veza ne nosi ništa osim poruke (nema praćenja), a red nema skripte ni rukovaoca događaja.
    #[Test]
    public function veze_nemaju_pracenje_skripte_ni_ruku_dogadjaja(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['rok' => '2026-12-15']);

        $html = $this->get(route('prilike.show', $prilika->slug))->assertOk()->getContent();
        preg_match('#<nav class="deljenje"[^>]*>(.*?)</nav>#s', $html, $red);

        $this->assertNotSame('', $red[1] ?? '');
        $this->assertStringNotContainsString('<script', $red[1]);
        $this->assertStringNotContainsString('<button', $red[1]);
        $this->assertStringNotContainsString('onclick', $red[1]);
        foreach ($this->veze($html) as [, $veza]) {
            $this->assertStringNotContainsString('utm_', $veza);
            $this->assertSame(1, substr_count($veza, '?'), 'jedan upitnik: početak poruke');
            $this->assertSame(0, substr_count($veza, '&'));
        }
        $this->assertStringContainsString('<span class="deljenje-naslov">'.PrikazDeljenja::NASLOV.'</span>', $html);
        $this->assertStringContainsString('<nav class="deljenje" aria-label="'.PrikazDeljenja::NASLOV.'">', $html);
    }

    // Ogledalo: red postoji samo na stranama jedne prilike, vodiča i organizacije; spiskovi ga nemaju, a nacrt je 404 bez ijedne veze.
    #[Test]
    public function red_je_samo_na_stranama_zapisa_a_nacrt_ga_ne_odaje(): void
    {
        Prilika::factory()->objavljena()->create(['rok' => '2026-12-15']);
        Vodic::factory()->objavljen()->create();
        Organizacija::factory()->objavljena()->create();

        foreach (['prilike.index', 'vodici.index', 'organizacije.index', 'kalendar.index', 'pomocnik.index', 'pocetna'] as $ruta) {
            $this->assertStringNotContainsString('class="deljenje"', $this->get(route($ruta))->assertOk()->getContent(), $ruta);
        }

        $nacrt = Prilika::factory()->create(['naslov' => 'Tajni nacrt']);
        $odgovor = $this->get(route('prilike.show', $nacrt->slug));

        $odgovor->assertNotFound();
        $this->assertStringNotContainsString('wa.me', $odgovor->getContent());
        $this->assertStringNotContainsString('viber://', $odgovor->getContent());
    }

    // Telefon prvo: veze su preko cele širine i visoke bar 44 px; tek od 720 px stoje jedna do druge.
    #[Test]
    public function dugmad_su_na_telefonu_preko_cele_sirine_a_veca_od_44_px(): void
    {
        $stil = $this->stilBezUpita();

        $this->assertMatchesRegularExpression('/\n\.deljenje \{[^}]*display: flex;[^}]*flex-direction: column;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-veza \{[^}]*display: flex;[^}]*min-height: 44px;/', $stil);
        $this->assertDoesNotMatchRegularExpression('/\n\.dugme-veza \{[^}]*(?:width|max-width): \d+px/', $stil);
        $this->assertMatchesRegularExpression('/@media \(min-width: 720px\) \{\s*\.deljenje \{\s*flex-direction: row;/', $this->stilBezKomentara());
    }
}
