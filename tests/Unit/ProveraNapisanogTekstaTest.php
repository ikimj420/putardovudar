<?php

namespace Tests\Unit;

use App\Services\Pomocnik\ProveraNapisanogTeksta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 56 T3: tekst koji napiše model važi samo ako sve što u njemu liči na datum, broj, e-poštu ili vezu stoji u onome što je čovek
// upisao ili u stalnom tekstu šablona. Šta je dozvoljeno prvo se dokazuje (prolazi), pa tek onda šta nije (pada).
class ProveraNapisanogTekstaTest extends TestCase
{
    private const DOZVOLJENO = "Ana Anić\nUlica Vuka Karadžića 12, Niš\n064 123 4567\nana.anic@primer.rs\nmoj sajt je www.anaanic.rs/o-meni\n11.10.2026.\nMolim vas da odgovorite u roku od 15 dana.";

    /** @return array<string, array{0: string}> */
    public static function dozvoljeniTekstovi(): array
    {
        return [
            'samo upisana imena' => ['Poštovani, ja sam Ana Anić.'],
            'datum kako je upisan' => ['Pišem vam 11.10.2026.'],
            'isti datum drugim rečima' => ['Pišem vam 11. oktobra 2026.'],
            'isti datum bez godine' => ['Pišem vam 11.10.'],
            'telefon sa drugim razmacima' => ['Zovite me na 064/123-4567.'],
            'telefon kako je upisan' => ['Zovite me na 064 123 4567.'],
            'e-pošta drugim slovima' => ['Pišite mi na Ana.Anic@Primer.rs.'],
            'veza sa https' => ['Više na https://www.anaanic.rs/o-meni.'],
            'veza bez www' => ['Više na anaanic.rs/o-meni.'],
            'broj iz upisane adrese' => ['Stanujem u Ulici Vuka Karadžića 12.'],
            'broj iz stalnog teksta šablona' => ['Molim vas da odgovorite u roku od 15 dana.'],
        ];
    }

    #[Test]
    #[DataProvider('dozvoljeniTekstovi')]
    public function tekst_samo_od_upisanog_prolazi(string $tekst): void
    {
        $this->assertNull((new ProveraNapisanogTeksta)->razlogOdbijanja($tekst, self::DOZVOLJENO));
    }

    /** @return array<string, array{0: string, 1: string}> tekst modela => razlog */
    public static function izmisljeniTekstovi(): array
    {
        return [
            'izmišljen datum' => ['Pišem vam 12.10.2026.', 'datum kakvog nema u odgovorima'],
            'izmišljen datum rečima' => ['Pišem vam 5. maja 2020.', 'datum kakvog nema u odgovorima'],
            'drugačija godina istog dana' => ['Pišem vam 11.10.2025.', 'datum kakvog nema u odgovorima'],
            'izmišljen telefon' => ['Zovite me na 065 765 4321.', 'broj kakvog nema u odgovorima'],
            'izmišljena e-pošta' => ['Pišite mi na ana@drugi.rs.', 'e-pošta kakve nema u odgovorima'],
            'izmišljena veza' => ['Više na https://izmisljeno.rs/ana.', 'veza kakve nema u odgovorima'],
            'veza bez adrese protokola' => ['Više na izmisljeno.com.', 'veza kakve nema u odgovorima'],
            'izmišljen broj godina' => ['Radila sam 7 godina u skladištu.', 'broj kakvog nema u odgovorima'],
            'izmišljena škola sa godinom' => ['Završila sam školu „Sveti Sava“ 2015. godine.', 'broj kakvog nema u odgovorima'],
            'redni broj u nabrajanju' => ["1. Prvo\n2. Drugo", 'broj kakvog nema u odgovorima'],
            'neispunjeno mesto za polje' => ['Poštovani {ime}', 'viticaste zagrade u tekstu'],
            'prazan tekst' => ["  \n ", 'prazan tekst'],
            'predug tekst' => [str_repeat('Ana Anić piše. ', 300), 'predug tekst'],
        ];
    }

    #[Test]
    #[DataProvider('izmisljeniTekstovi')]
    public function izmisljen_podatak_obara_tekst(string $tekst, string $razlog): void
    {
        $this->assertSame($razlog, (new ProveraNapisanogTeksta)->razlogOdbijanja($tekst, self::DOZVOLJENO));
    }

    // Merilo mora da obiđe oba skupa: bez dozvoljenog teksta ni upisan telefon ne prolazi.
    #[Test]
    public function bez_dozvoljenog_teksta_ni_upisan_telefon_ne_prolazi(): void
    {
        $this->assertNotSame([], self::dozvoljeniTekstovi());
        $this->assertNotSame([], self::izmisljeniTekstovi());
        $this->assertSame('broj kakvog nema u odgovorima', (new ProveraNapisanogTeksta)->razlogOdbijanja('Zovite me na 064 123 4567.', ''));
    }
}
