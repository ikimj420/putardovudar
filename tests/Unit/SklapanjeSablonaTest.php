<?php

namespace Tests\Unit;

use App\Enums\Rod;
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

    /** @param  array<string, mixed>  $dopune ključ polja → dodatni ključevi (ako_prazno, opciono) */
    private function sablonSaPravilima(string $tekst, array $dopune): Sablon
    {
        $polja = [];

        foreach (['ime', 'kome', 'tekst', 'skola', 'kursevi'] as $kljuc) {
            $polja[] = ['kljuc' => $kljuc, 'oznaka' => ucfirst($kljuc), 'dugo' => false, ...($dopune[$kljuc] ?? [])];
        }

        return new Sablon(['naslov' => 'Molba', 'objasnjenje' => 'Opšti oblik.', 'tekst' => $tekst, 'polja' => $polja]);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}> tekst šablona, pravila, upisano, očekivano */
    public static function pravilaZaPrazno(): array
    {
        $pen = SklapanjeSablona::PRAZNO;

        return [
            'podrazumevana reč' => ["{kome},\n\n{tekst}", ['kome' => ['ako_prazno' => 'Poštovani']], ['tekst' => 'Tekst.'], "Poštovani,\n\nTekst."],
            'upisano ima prednost nad podrazumevanom' => ["{kome},\n\n{tekst}", ['kome' => ['ako_prazno' => 'Poštovani']], ['kome' => 'Draga Ano', 'tekst' => 'Tekst.'], "Draga Ano,\n\nTekst."],
            'samo razmaci su prazno, pa važi podrazumevana' => ["{kome},\n\n{tekst}", ['kome' => ['ako_prazno' => 'Poštovani']], ['kome' => "  \n ", 'tekst' => 'Tekst.'], "Poštovani,\n\nTekst."],
            'opcioni red u sredini odeljka' => ["{ime}\n{skola}\n{tekst}", ['skola' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\nTekst."],
            'opcioni red, popunjen' => ["{ime}\n{skola}\n{tekst}", ['skola' => ['opciono' => true]], ['ime' => 'Ana', 'skola' => 'Škola', 'tekst' => 'Tekst.'], "Ana\nŠkola\nTekst."],
            'odeljak sa naslovom bez polja se gubi ceo' => ["{ime}\n\nKursevi\n{kursevi}\n\n{tekst}", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n\nTekst."],
            'odeljak na početku' => ["Kursevi\n{kursevi}\n\n{ime}\n\n{tekst}", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n\nTekst."],
            'odeljak na kraju' => ["{ime}\n\n{tekst}\n\nKursevi\n{kursevi}", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n\nTekst."],
            'odeljak sa dva reda, oba opciona' => ["{ime}\n\n{skola}\n{kursevi}\n\n{tekst}", ['skola' => ['opciono' => true], 'kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n\nTekst."],
            'odeljak sa dva reda, jedan opcioni prazan' => ["{ime}\n\n{skola}\n{kursevi}\n\n{tekst}", ['skola' => ['opciono' => true]], ['ime' => 'Ana', 'kursevi' => 'Kurs', 'tekst' => 'Tekst.'], "Ana\n\nKurs\n\nTekst."],
            'ostalo prazno je linija za olovku' => ["{ime}\n\n{kursevi}\n\n{tekst}", ['kursevi' => ['opciono' => true]], ['tekst' => 'Tekst.'], $pen."\n\nTekst."],
            'razmak od dva prazna reda se čuva' => ["{ime}\n\n\nKursevi\n{kursevi}\n\n\n{tekst}", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n\n\nTekst."],
            'red sa više mesta, jedno opciono prazno' => ["{ime}\nŠkola: {skola}, {tekst}\nKraj", ['skola' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\nKraj"],
            'podrazumevana reč pobeđuje izostavljanje' => ["{ime}\n{skola}\n{tekst}", ['skola' => ['opciono' => true, 'ako_prazno' => 'Nije navedeno']], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\nNije navedeno\nTekst."],
            'opciono samo kad je tačno true' => ["{ime}\n{skola}\n{tekst}", ['skola' => ['opciono' => 'da']], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n".$pen."\nTekst."],
            'bez pravila sve ostaje kao pre' => ["{ime}\n{skola}\n{tekst}", [], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n".$pen."\nTekst."],
            'statični odeljak bez mesta se ne dira' => ["{ime}\n\nRado ću doći na razgovor.\n\nKursevi\n{kursevi}", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana'], "Ana\n\nRado ću doći na razgovor."],
            'novi redovi na početku i kraju šablona se čuvaju' => ["\n{ime}\n\nKursevi\n{kursevi}\n\n{tekst}\n", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "\nAna\n\nTekst.\n"],
            'novi redovi Windows' => ["{ime}\r\n\r\nKursevi\r\n{kursevi}\r\n\r\n{tekst}", ['kursevi' => ['opciono' => true]], ['ime' => 'Ana', 'tekst' => 'Tekst.'], "Ana\n\nTekst."],
        ];
    }

    /**
     * @param  array<string, mixed>  $pravila
     * @param  array<string, mixed>  $unosi
     */
    #[Test]
    #[DataProvider('pravilaZaPrazno')]
    public function pravila_uz_polje_odredjuju_sta_pise_umesto_praznog(string $tekst, array $pravila, array $unosi, string $ocekivano): void
    {
        $this->assertSame($ocekivano, SklapanjeSablona::sklopi($this->sablonSaPravilima($tekst, $pravila), $unosi));
    }

    // Isti rodni oblik i isto tumačenje mesta kad se red izostavlja.
    #[Test]
    public function izostavljen_red_ne_menja_rodni_oblik_u_ostalom_tekstu(): void
    {
        $sablon = $this->sablonSaPravilima("{ime}\n{skola}\nRadio/radila sam. {radio/radila} sam.", ['skola' => ['opciono' => true]]);

        $this->assertSame("Ana\nRadio/radila sam. radila sam.", SklapanjeSablona::sklopi($sablon, ['ime' => 'Ana'], Rod::Zenski));
    }

    #[Test]
    public function podrazumevane_reci_ulaze_u_upisano_a_tekst_sablona_nema_izostavljene_redove(): void
    {
        $sablon = $this->sablonSaPravilima("{kome},\n{skola}\n{tekst}", ['kome' => ['ako_prazno' => 'Poštovani'], 'skola' => ['opciono' => true]]);

        $this->assertSame(['ime' => 'Ana', 'kome' => 'Poštovani'], SklapanjeSablona::unosiSaPodrazumevanim($sablon, ['ime' => 'Ana']));
        $this->assertSame(['kome' => 'Draga Ano'], SklapanjeSablona::unosiSaPodrazumevanim($sablon, ['kome' => 'Draga Ano']));
        $this->assertSame("{kome},\n{tekst}", SklapanjeSablona::tekstSablona($sablon, [], null));
        $this->assertSame("{kome},\n{skola}\n{tekst}", SklapanjeSablona::tekstSablona($sablon, ['skola' => 'Škola'], null));
    }

    // Ono što je upisano ne tumači se kao pravilo ni kao mesto: podrazumevana reč „{ime}" ostaje tekst.
    #[Test]
    public function podrazumevana_rec_se_ne_razvija_kao_mesto(): void
    {
        $sablon = $this->sablonSaPravilima("{kome}\n{ime}", ['kome' => ['ako_prazno' => '{ime}']]);

        $this->assertSame("{ime}\nAna", SklapanjeSablona::sklopi($sablon, ['ime' => 'Ana']));
    }
}
