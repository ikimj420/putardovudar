<?php

namespace Tests\Feature\Baza;

use App\Models\Organizacija;
use App\Support\PrikazOrganizacija;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\StilJavnihStrana;

// Paket 48: dugme „Pozovi" (veza „tel:") na kartici i strani organizacije, samo kad u polju telefon ima broj.
#[Group('baza')]
class PozoviOrganizacijuTest extends BazaTestCase
{
    use RefreshDatabase, StilJavnihStrana;

    /** @return array<string, array{0: string, 1: string}> upisan telefon, broj u vezi */
    public static function brojevi(): array
    {
        return [
            'kosa crta i crtica' => ['011/123-456', '011123456'],
            'razmaci' => ['011 123 456', '011123456'],
            'crtice' => ['011-123-456', '011123456'],
            'tačke' => ['011.123.456', '011123456'],
            'zagrada' => ['(011) 123-456', '011123456'],
            'plus 381' => ['+381 11 123 456', '+38111123456'],
            'plus 381 sa nulom u zagradi' => ['+381 (0)11 123 456', '+38111123456'],
            'plus 381 sa crticama' => ['+381-11-123-456', '+38111123456'],
            'besplatan broj' => ['0800 300 301', '0800300301'],
            'mobilni' => ['063/111-222', '063111222'],
            'dva broja: prvi' => ['011/123-456, 063/111-222', '011123456'],
            'tekst oko broja' => ['Centrala: 011/123-456 (radnim danom)', '011123456'],
            'bez razmaka' => ['011123456', '011123456'],
        ];
    }

    /** @return array<string, array{0: string|null}> */
    public static function bezBroja(): array
    {
        return [
            'prazno' => [null], 'prazan tekst' => [''], 'samo razmaci' => ['   '], 'bez cifara' => ['preko e-pošte'],
            'prekratko' => ['12-3'], 'samo plus' => ['+'], 'cifre razdvojene slovima' => ['1 a 2 b 3'],
            'premalo cifara među crticama' => ['1 - - 2'], 'četiri cifre' => ['12-3-4'],
        ];
    }

    #[Test]
    #[DataProvider('brojevi')]
    public function veza_nosi_broj_bez_razmaka_crtica_i_kose_crte(string $telefon, string $broj): void
    {
        $this->assertSame($broj, PrikazOrganizacija::brojZaPoziv(new Organizacija(['telefon' => $telefon])), $telefon);

        $organizacija = Organizacija::factory()->objavljena()->create(['naziv' => 'Pomoć za sve', 'telefon' => $telefon]);

        // Strana i kartica na spisku nose istu vezu, sa imenom organizacije za čitač ekrana.
        foreach ([route('organizacije.show', $organizacija->slug), route('organizacije.index')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'href="tel:'), $adresa);
            $this->assertStringContainsString('<a class="dugme-veza" href="tel:'.$broj.'" aria-label="Pozovi: Pomoć za sve">Pozovi</a>', $html, $adresa);
        }
    }

    // Ogledalo: bez broja nema dugmeta ni na strani ni na kartici, a telefon upisan kao tekst ostaje u oznakama strane.
    #[Test]
    #[DataProvider('bezBroja')]
    public function bez_broja_nema_dugmeta(?string $telefon): void
    {
        $this->assertNull(PrikazOrganizacija::brojZaPoziv(new Organizacija(['telefon' => $telefon])));

        $organizacija = Organizacija::factory()->objavljena()->create(['telefon' => $telefon]);

        foreach ([route('organizacije.show', $organizacija->slug), route('organizacije.index')] as $adresa) {
            $html = $this->get($adresa)->assertOk()->getContent();

            $this->assertStringNotContainsString('href="tel:', $html, $adresa);
            $this->assertStringNotContainsString('>Pozovi<', $html, $adresa);
        }
    }

    // Spisak: dugme samo uz kartice koje imaju broj; svaka nosi svoj broj (merilo broji, pa mora da obiđe kartice sa brojem i bez njega).
    #[Test]
    public function na_spisku_dugme_ima_samo_kartica_sa_brojem_i_svaka_svoj(): void
    {
        Organizacija::factory()->objavljena()->create(['naziv' => 'Prva', 'telefon' => '011/111-111']);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Druga', 'telefon' => null]);
        Organizacija::factory()->objavljena()->create(['naziv' => 'Treća', 'telefon' => '+381 21 222 333']);

        $html = $this->get(route('organizacije.index'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<article class="kartica redosled">'));
        preg_match_all('#<a class="dugme-veza" href="(tel:[^"]+)" aria-label="Pozovi: ([^"]+)">#', $html, $nalazi, PREG_SET_ORDER);
        $this->assertSame([['tel:011111111', 'Prva'], ['tel:+38121222333', 'Treća']], array_map(fn (array $n) => [$n[1], $n[2]], $nalazi));
    }

    // Nacrt je za posetioca nepostojeći: ni strana ni dugme.
    #[Test]
    public function nacrt_nema_ni_stranu_ni_dugme(): void
    {
        $nacrt = Organizacija::factory()->create(['telefon' => '011/123-456']);

        $this->get(route('organizacije.show', $nacrt->slug))->assertNotFound();
        $this->assertStringNotContainsString('tel:', $this->get(route('organizacije.index'))->getContent());
    }

    // Telefon prvo: dugme je preko cele širine i visoko bar 44 px; od 720 px na strani stoji samo, uz leve ivicu.
    #[Test]
    public function dugme_je_na_telefonu_preko_cele_sirine(): void
    {
        $stil = $this->stilBezUpita();

        $this->assertMatchesRegularExpression('/\n\.dugme-red \{[^}]*display: flex;[^}]*flex-direction: column;/', $stil);
        $this->assertMatchesRegularExpression('/\n\.dugme-veza \{[^}]*min-height: 44px;/', $stil);
        $this->assertMatchesRegularExpression('/@media \(min-width: 720px\) \{[^@]*\.dugme-red \{\s*flex-direction: row;/', $this->stilBezKomentara());
    }

    // Dugme na kartici stoji ispod svega ostalog: njegov red je veći od redova oznake, naslova, opisa i podataka, a njih ima (merilo broji).
    #[Test]
    public function dugme_na_kartici_je_poslednje_u_redosledu(): void
    {
        $stil = $this->stilBezKomentara();

        preg_match_all('/\n\.redosled \.?([a-z0-9-]+) \{[^}]*?order: (\d+);/', $stil, $redovi, PREG_SET_ORDER);
        $drugi = array_column(array_filter($redovi, fn (array $red) => $red[1] !== 'dugme-veza'), 2, 1);
        preg_match('/\n\.redosled \.dugme-veza \{[^}]*?order: (\d+);/', $stil, $dugme);

        $this->assertSame(['oznake', 'h2', 'kratko', 'meta'], array_keys($drugi));
        $this->assertGreaterThan(max(array_map('intval', $drugi)), (int) ($dugme[1] ?? 0));
    }
}
