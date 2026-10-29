<?php

namespace Tests\Feature\Baza;

use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 80/T2 (Ivan, 29.10.2026.): podnožje na svim javnim stranama — ime sajta i slogan, veze „Privatnost" i „Predloži priliku", bez spoljnih adresa.
#[Group('baza')]
class PodnozjeTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    /** @return array<string, array{0: string}> ključ strane; adresa se pravi u testu */
    public static function strane(): array
    {
        return [
            'početna' => ['pocetna'], 'prilike' => ['prilike.index'], 'prilika' => ['prilike.show'], 'vodiči' => ['vodici.index'], 'vodič' => ['vodici.show'],
            'organizacije' => ['organizacije.index'], 'organizacija' => ['organizacije.show'], 'kalendar' => ['kalendar.index'], 'pomoćnik' => ['pomocnik.index'],
            'napiši moj' => ['pomocnik.napisi.forma'], 'predloži priliku' => ['predlozi.forma'], 'privatnost' => ['privatnost'], 'nepostojeća strana' => ['404'],
        ];
    }

    private function adresa(string $kljuc): string
    {
        return match ($kljuc) {
            'prilike.show' => route($kljuc, Prilika::factory()->objavljena()->create()->slug),
            'vodici.show' => route($kljuc, Vodic::factory()->objavljen()->create()->slug),
            'organizacije.show' => route($kljuc, Organizacija::factory()->objavljena()->create()->slug),
            'pomocnik.napisi.forma' => route($kljuc, Sablon::factory()->objavljen()->create()->slug),
            '404' => url('/nema-takve-strane'),
            default => route($kljuc),
        };
    }

    #[Test]
    #[DataProvider('strane')]
    public function podnozje_je_na_svakoj_javnoj_strani_i_nosi_ime_slogan_i_dve_veze(string $kljuc): void
    {
        $html = $this->get($this->adresa($kljuc))->getContent();

        $this->assertSame(1, preg_match_all('#<footer class="podnozje">#', $html), $kljuc);
        preg_match('#<footer class="podnozje">(.*?)</footer>#s', $html, $podnozje);
        preg_match('#<p class="podnozje-ime">(.*?)</p>#s', $podnozje[1], $ime);
        preg_match('#<nav class="podnozje-meni" aria-label="([^"]*)">(.*?)</nav>#s', $podnozje[1], $meni);
        preg_match_all('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#s', $meni[2], $veze, PREG_SET_ORDER);

        $this->assertSame('Putardo Vudar Otvorena vrata', trim((string) preg_replace('/\s+/', ' ', strip_tags($ime[1]))), $kljuc);
        $this->assertSame('Podnožje', $meni[1]);
        $this->assertSame(['Privatnost', 'Predloži priliku'], array_map(fn (array $veza) => trim($veza[2]), $veze), $kljuc);
        $this->assertSame([route('privatnost'), route('predlozi.forma')], array_map(fn (array $veza) => $veza[1], $veze), $kljuc);
        // Podnožje je posle sadržaja, a pre plutajućeg pomoćnika (koji je poslednji).
        $this->assertLessThan(strpos($html, '<footer class="podnozje">'), strpos($html, '</main>'));
    }

    // Ogledalo: u podnožju nema nijedne veze van sajta, a strane sa spoljnim vezama u sadržaju (prilika) ih i dalje imaju.
    #[Test]
    public function podnozje_nema_spoljnih_adresa_a_sadrzaj_ih_ima(): void
    {
        $prilika = Prilika::factory()->objavljena()->create(['link_izvora' => 'https://izvor.example.org/konkurs']);
        $html = $this->get(route('prilike.show', $prilika->slug))->getContent();
        $moj = parse_url(url('/'), PHP_URL_HOST);

        preg_match('#<footer class="podnozje">(.*?)</footer>#s', $html, $podnozje);
        preg_match_all('#href="(https?://[^"]+)"#', $podnozje[1], $uPodnozju);
        preg_match_all('#href="(https?://[^"]+)"#', $html, $naStrani);

        $this->assertNotEmpty($uPodnozju[1]);
        $this->assertSame([$moj], array_values(array_unique(array_map(fn (string $adresa) => parse_url($adresa, PHP_URL_HOST), $uPodnozju[1]))));
        $this->assertContains('izvor.example.org', array_map(fn (string $adresa) => parse_url($adresa, PHP_URL_HOST), $naStrani[1]));
    }

    // Veza koja vodi na stranu u kojoj si nosi oznaku „ovde si"; na ostalim stranama je nema.
    #[Test]
    public function veza_podnozja_nosi_oznaku_samo_na_svojoj_strani(): void
    {
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('privatnost'), '#').'" aria-current="page">#', $this->get(route('privatnost'))->getContent());
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('predlozi.forma'), '#').'" aria-current="page">#', $this->get(route('predlozi.forma'))->getContent());

        $podnozje = fn (string $adresa) => (string) preg_replace('#^.*<footer class="podnozje">(.*?)</footer>.*$#s', '$1', $this->get($adresa)->getContent());
        $this->assertStringNotContainsString('aria-current', $podnozje(route('prilike.index')));
        $this->assertStringNotContainsString('aria-current', $podnozje(route('organizacije.index')));
        $this->assertSame(1, substr_count($podnozje(route('privatnost')), 'aria-current'));
    }

    // Podloga podnožja je tamna (--noc), pa obris fokusa mora biti zlatan: ljubičast se na njoj ne vidi. Ogledalo: na svetlim površinama ostaje osnovni obris.
    #[Test]
    public function fokus_u_podnozju_je_zlatan_jer_je_podloga_tamna(): void
    {
        $stil = $this->stilBezKomentara();

        $this->assertMatchesRegularExpression('/\n\.podnozje \{[^}]*background: var\(--noc\);/', $stil);
        $this->assertMatchesRegularExpression('/\n\.podnozje :focus-visible \{\s*outline-color: var\(--zlato\);\s*\}/', $stil);
        $this->assertMatchesRegularExpression('/\n:focus-visible \{\s*outline: 3px solid var\(--fokus\);/', $stil);
    }
}
