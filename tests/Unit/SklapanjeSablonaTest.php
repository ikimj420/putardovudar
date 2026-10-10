<?php

namespace Tests\Unit;

use App\Models\Sablon;
use App\Support\SklapanjeSablona;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 51: sklapanje teksta iz šablona i onoga što je čovek upisao. Prazno polje nikad ne ostavlja „{ime}" u tekstu.
class SklapanjeSablonaTest extends TestCase
{
    private function sablon(string $tekst = "{ime}\n\n{kome}: {tekst}", ?array $polja = null): Sablon
    {
        return new Sablon([
            'naslov' => 'Molba', 'objasnjenje' => 'Opšti oblik.', 'tekst' => $tekst,
            'polja' => $polja ?? [['kljuc' => 'ime', 'oznaka' => 'Ime'], ['kljuc' => 'kome', 'oznaka' => 'Kome'], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'dugo' => true]],
        ]);
    }

    #[Test]
    public function popunjena_polja_ulaze_u_tekst_na_svoja_mesta(): void
    {
        $this->assertSame("Ana Anić\n\nŠkoli: Molim za upis.", SklapanjeSablona::sklopi($this->sablon(), ['ime' => 'Ana Anić', 'kome' => 'Školi', 'tekst' => 'Molim za upis.']));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function praznaPolja(): array
    {
        return [
            'nijedno polje' => [[]],
            'prazan tekst' => [['ime' => '', 'kome' => '', 'tekst' => '']],
            'samo razmaci i novi redovi' => [['ime' => "  \n\t ", 'kome' => ' ', 'tekst' => "\n\n"]],
            'null' => [['ime' => null, 'kome' => null, 'tekst' => null]],
            'niz umesto teksta' => [['ime' => ['a'], 'kome' => 5, 'tekst' => true]],
        ];
    }

    // Ogledalo prethodnog testa: kad polja nisu popunjena, na njihovom mestu je linija za olovku, a „{…}" nigde ne ostaje.
    #[Test]
    #[DataProvider('praznaPolja')]
    public function prazno_polje_ne_ostavlja_viticaste_zagrade_nego_liniju_za_olovku(array $unosi): void
    {
        $tekst = SklapanjeSablona::sklopi($this->sablon(), $unosi);

        $this->assertSame(SklapanjeSablona::PRAZNO."\n\n".SklapanjeSablona::PRAZNO.': '.SklapanjeSablona::PRAZNO, $tekst);
        $this->assertStringNotContainsString('{', $tekst);
        $this->assertStringNotContainsString('}', $tekst);
    }

    // Delimično popunjen: popunjeno ostaje, prazno postaje linija.
    #[Test]
    public function popunjeno_i_prazno_polje_u_istom_tekstu(): void
    {
        $this->assertSame("Ana\n\n".SklapanjeSablona::PRAZNO.': Tekst.', SklapanjeSablona::sklopi($this->sablon(), ['ime' => 'Ana', 'kome' => '', 'tekst' => 'Tekst.']));
    }

    // Mesto u tekstu bez polja (greška u šablonu) takođe ne ostaje „{…}".
    #[Test]
    public function mesto_bez_polja_postaje_linija_a_ne_viticaste_zagrade(): void
    {
        $tekst = SklapanjeSablona::sklopi($this->sablon('Dragi {nepoznato}, {ime}'), ['ime' => 'Ana', 'nepoznato' => 'ne važi']);

        $this->assertSame('Dragi '.SklapanjeSablona::PRAZNO.', Ana', $tekst);
    }

    // Upisano se ne razvija ponovo: „{kome}" upisano u polje ostaje kako je upisano, ne postaje sadržaj drugog polja.
    #[Test]
    public function upisano_se_ne_tumaci_kao_mesto_u_tekstu(): void
    {
        $tekst = SklapanjeSablona::sklopi($this->sablon(), ['ime' => '{kome}', 'kome' => 'Školi', 'tekst' => '{ime}']);

        $this->assertSame("{kome}\n\nŠkoli: {ime}", $tekst);
    }

    #[Test]
    public function novi_redovi_ostaju_a_upravljacki_znaci_ispadaju(): void
    {
        $tekst = SklapanjeSablona::sklopi($this->sablon('{tekst}'), ['tekst' => "Prvi red\r\nDrugi\rTreći\x00\x07 red\u{200B}!"]);

        $this->assertSame("Prvi red\nDrugi\nTreći red!", $tekst);
    }

    #[Test]
    public function predugo_polje_se_skracuje_na_najvise_znakova(): void
    {
        $tekst = SklapanjeSablona::sklopi($this->sablon('{tekst}'), ['tekst' => str_repeat('š', SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA + 50)]);

        $this->assertSame(SklapanjeSablona::NAJVISE_ZNAKOVA_POLJA, mb_strlen($tekst));
    }

    // Ključevi u tekstu: redom, bez ponavljanja; samo mala slova, cifre i donja crta, počinje slovom.
    #[Test]
    public function kljucevi_u_tekstu_se_prepoznaju_samo_u_pravom_obliku(): void
    {
        $this->assertSame(['ime', 'kome_pisem'], SklapanjeSablona::kljuceUTekstu('{ime} piše {kome_pisem}, {ime} {Ime} { ime } {1x} {a-b} {}'));
        $this->assertSame([], SklapanjeSablona::kljuceUTekstu('bez mesta'));
        $this->assertSame(['ime'], (new Sablon(['tekst' => '{ime}', 'polja' => [['kljuc' => 'ime']]]))->kljucevi());
    }
}
