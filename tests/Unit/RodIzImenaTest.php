<?php

namespace Tests\Unit;

use App\Enums\Rod;
use App\Support\RodIzImena;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 64/T6: rod iz imena. Šta se ne prepozna ili može biti oba, vraća null, pa pomoćnik pita.
class RodIzImenaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: Rod|null}> */
    public static function imena(): array
    {
        $m = Rod::Muski;
        $z = Rod::Zenski;

        return [
            'Ana' => ['Ana', $z],
            'Marko' => ['Marko', $m],
            'ime i prezime' => ['Ana Marković', $z],
            'prezime se ne gleda' => ['Marko Petrovića', $m],
            'prezime na a, ime muško' => ['Marko Kosta', $m],
            'mala slova' => ['marko petrović', $m],
            'velika slova' => ['ANA', $z],
            'razmaci oko imena' => ['   Ana   Anić ', $z],
            'spojeno ime' => ['Ana-Marija Anić', $z],
            'ćirilica' => ['Ана Петровић', $z],
            'ćirilica muško' => ['Марко Петровић', $m],
            'Marija' => ['Marija', $z],
            'Jelena' => ['Jelena', $z],
            'Nataša' => ['Nataša', $z],
            'Natasa bez dijakritika' => ['Natasa', $z],
            'Dragan' => ['Dragan', $m],
            'Miloš' => ['Miloš', $m],
            'Mihailo' => ['Mihailo', $m],
            'Elvis' => ['Elvis', $m],
            'muško na a: Nikola' => ['Nikola', $m],
            'muško na a: Luka' => ['Luka', $m],
            'muško na a: Nemanja' => ['Nemanja', $m],
            'muško na a: Nebojša' => ['Nebojša', $m],
            'muško na a: Nebojsa bez dijakritika' => ['Nebojsa', $m],
            'muško na ija: Ilija' => ['Ilija', $m],
            'muško na ija: Andrija' => ['Andrija', $m],
            'ženski na ija: Marija' => ['Marija', $z],
            'muško na e: Đorđe' => ['Đorđe', $m],
            'muško na e: Djordje bez dijakritika' => ['Djordje', $m],
            'muško na e: Vasilije' => ['Vasilije', $m],
            'žensko bez a: Ines' => ['Ines', $z],
            'žensko bez a: Karmen' => ['Karmen', $z],
            // Ime može biti i muško i žensko: pita se.
            'Saša' => ['Saša', null],
            'Sasa bez dijakritika' => ['Sasa', null],
            'SAŠA velikim slovima' => ['SAŠA Petrović', null],
            'Vanja' => ['Vanja', null],
            'Andrea' => ['Andrea', null],
            'Dobrica' => ['Dobrica', null],
            // Ne prepoznaje se: pita se.
            'nadimak na i' => ['Kiki', null],
            'Toni' => ['Toni', null],
            'Dani' => ['Dani', null],
            'nepoznat završetak e' => ['Mare', null],
            'završetak u' => ['Ruku', null],
            'prazno' => ['', null],
            'samo razmaci' => ['   ', null],
            'znaci umesto imena' => ['???', null],
            'broj umesto imena' => ['12345', null],
            'jedno slovo' => ['A', null],
            'html umesto imena' => ['<script>alert(1)</script>', null],
        ];
    }

    #[Test]
    #[DataProvider('imena')]
    public function rod_se_zna_iz_imena_a_nejasno_ime_ne_daje_rod(string $ime, ?Rod $ocekivano): void
    {
        $this->assertSame($ocekivano, RodIzImena::odredi($ime));
    }

    // Ogledalo za merilo: spiskovi nisu prazni, i svako ime sa spiska daje onaj rod koji spisak kaže.
    #[Test]
    public function svako_ime_sa_spiska_daje_rod_koji_spisak_kaze(): void
    {
        foreach ([RodIzImena::OBA, RodIzImena::MUSKA_NA_A, RodIzImena::MUSKA_NA_E, RodIzImena::ZENSKA_BEZ_A] as $spisak) {
            $this->assertNotEmpty($spisak);
        }

        foreach (RodIzImena::OBA as $ime) {
            $this->assertNull(RodIzImena::odredi($ime), $ime);
        }

        foreach ([...RodIzImena::MUSKA_NA_A, ...RodIzImena::MUSKA_NA_E] as $ime) {
            $this->assertSame(Rod::Muski, RodIzImena::odredi($ime), $ime);
        }

        foreach (RodIzImena::ZENSKA_BEZ_A as $ime) {
            $this->assertSame(Rod::Zenski, RodIzImena::odredi($ime), $ime);
        }
    }

    #[Test]
    public function suprotan_rod_je_drugi_rod(): void
    {
        $this->assertSame(Rod::Zenski, Rod::Muski->suprotan());
        $this->assertSame(Rod::Muski, Rod::Zenski->suprotan());
    }
}
