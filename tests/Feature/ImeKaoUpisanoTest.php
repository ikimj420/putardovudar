<?php

namespace Tests\Feature;

use App\Services\Pomocnik\ProveraNapisanogTeksta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Paket 67/T11 (Ivan, 11.10.2026.): kad šablon ima polje „ime", tekst modela prolazi proveru samo ako upisano ime i prezime stoji u njemu bar jednom, isto, bez obzira na velika
// slova i višak razmaka. Primer iz probe: upisano „Marko Marković", model napisao „Marka Marković". Pogrešni glagoli („Unosala") se ne proveravaju.
class ImeKaoUpisanoTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: ?string}> upisano ime, tekst modela, očekivani razlog */
    public static function imena(): array
    {
        $odbijeno = 'ime nije kako je upisano';

        return [
            // Ne prolaze.
            'Marka umesto Marko (iz probe)' => ['Marko Marković', "Poštovani,\n\nPraksa dve nedelje.\n\nS poštovanjem,\nMarka Marković", $odbijeno],
            'ime bez prezimena' => ['Marko Marković', 'S poštovanjem, Marko', $odbijeno],
            'prezime bez imena' => ['Marko Marković', 'S poštovanjem, Marković', $odbijeno],
            'obrnut redosled' => ['Marko Marković', 'S poštovanjem, Marković Marko', $odbijeno],
            'ime nije u tekstu' => ['Marko Marković', 'Poštovani, javljam se zbog prakse.', $odbijeno],
            'druga dijakritika' => ['Marko Marković', 'S poštovanjem, Marko Markovic', $odbijeno],
            'nešto umetnuto u ime' => ['Marko Marković', 'S poštovanjem, Marko M. Marković', $odbijeno],
            'ime u drugom padežu' => ['Marko Marković', 'Pismo od Marko Markovića', $odbijeno],
            'prezime nastavljeno u drugu reč' => ['Marko Marković', 'S poštovanjem, Marko Markovićka', $odbijeno],
            'ime kao deo druge reči' => ['Ana', 'S poštovanjem, Banana', $odbijeno],
            // Prolaze.
            'isto ime' => ['Marko Marković', 'S poštovanjem, Marko Marković', null],
            'ime upisano malim slovima' => ['marko marković', 'S poštovanjem, Marko Marković', null],
            'ime upisano velikim slovima' => ['MARKO MARKOVIĆ', 'S poštovanjem, Marko Marković', null],
            'ime u tekstu velikim slovima' => ['Marko Marković', 'S POŠTOVANJEM, MARKO MARKOVIĆ', null],
            'višak razmaka u upisanom' => ['  Marko    Marković ', 'S poštovanjem, Marko Marković', null],
            'višak razmaka u tekstu' => ['Marko Marković', "S poštovanjem,\nMarko   Marković", null],
            'ime je u tekstu više puta' => ['Marko Marković', 'Marko Marković se javlja. S poštovanjem, Marko Marković', null],
            'ime iza interpunkcije i pre nje' => ['Marko Marković', '(Marko Marković), tel. 011', null],
            'ime je u tekstu jednom usred rečenice' => ['Marko Marković', 'Javlja se Marko Marković zbog prakse.', null],
            'samo ime upisano' => ['Marko', 'S poštovanjem, Marko', null],
            'ime sa znakovima' => ['Mirjeta Krasniqi Gashi', 'S poštovanjem, Mirjeta Krasniqi Gashi', null],
            'ime Zoë' => ['Zoë Krasniqi', 'S poštovanjem, Zoë Krasniqi', null],
            'ćirilica upisana, latinica u tekstu' => ["\u{041C}\u{0430}\u{0440}\u{043A}\u{043E} \u{041C}\u{0430}\u{0440}\u{043A}\u{043E}\u{0432}\u{0438}\u{045B}", 'S poštovanjem, Marko Marković', null],
            // Nema šta da se traži.
            'prazno upisano ime' => ['', 'S poštovanjem', null],
            'samo razmaci upisani' => ['   ', 'S poštovanjem', null],
        ];
    }

    #[Test]
    #[DataProvider('imena')]
    public function ime_mora_da_stoji_u_tekstu_kako_je_upisano(string $ime, string $tekst, ?string $razlog): void
    {
        $this->assertSame($razlog, (new ProveraNapisanogTeksta)->razlogOdbijanja($tekst, $ime."\n".$tekst, $ime));
    }

    // Šablon bez polja „ime" ne šalje ime (null) i radi kao pre: nijedan tekst se ne odbija zbog imena.
    #[Test]
    public function bez_polja_ime_ime_se_ne_trazi(): void
    {
        $this->assertNull((new ProveraNapisanogTeksta)->razlogOdbijanja('Poštovani, javljam se zbog prakse.', 'Poštovani, javljam se zbog prakse.'));
        $this->assertNull((new ProveraNapisanogTeksta)->razlogOdbijanja('Poštovani, javljam se zbog prakse.', 'Poštovani, javljam se zbog prakse.', null));
        // Ogledalo: isti tekst sa zahtevanim imenom pada.
        $this->assertSame('ime nije kako je upisano', (new ProveraNapisanogTeksta)->razlogOdbijanja('Poštovani, javljam se zbog prakse.', 'Poštovani, javljam se zbog prakse.', 'Marko Marković'));
    }

    // Provera imena ne sme da sakrije ranije razloge, a ni da se prođe pored datuma: prazan tekst i predug tekst imaju svoje razloge.
    #[Test]
    public function ranije_provere_ostaju_pre_imena(): void
    {
        $this->assertSame('prazan tekst', (new ProveraNapisanogTeksta)->razlogOdbijanja('', '', 'Marko Marković'));
        $this->assertSame('predug tekst', (new ProveraNapisanogTeksta)->razlogOdbijanja(str_repeat('Marko Marković ', 400), '', 'Marko Marković'));
        $this->assertSame('slova koja nisu latinica', (new ProveraNapisanogTeksta)->razlogOdbijanja('Marko Marković 限', '', 'Marko Marković'));
    }
}
