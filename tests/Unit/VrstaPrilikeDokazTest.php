<?php

namespace Tests\Unit;

use App\Enums\VrstaPrilike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 40: vrsta prilike se dokazuje rečju sa strane. Svaka vrsta ima primere sa rečju i primere koji liče, a ne prolaze.
class VrstaPrilikeDokazTest extends TestCase
{
    /** @return array<string, array{0: VrstaPrilike, 1: string}> */
    private static function skup(array $primeri): array
    {
        $skup = [];

        foreach ($primeri as $vrednost => $tekstovi) {
            foreach ($tekstovi as $tekst) {
                $skup[$vrednost.': '.$tekst] = [VrstaPrilike::from($vrednost), $tekst];
            }
        }

        return $skup;
    }

    /** @return array<string, array{0: VrstaPrilike, 1: string}> */
    public static function saRecju(): array
    {
        return self::skup([
            'posao' => ['Oglas za posao u Nišu', 'ima posla za vozače', 'Radi se o poslovima u ugostiteljstvu', 'Zapošljavanje mladih', 'otvoreno radno mesto', 'ZAPOSLENJE', 'zaposleni radnici', 'ПОСАО У БЕОГРАДУ'],
            'praksa' => ['Plaćena stručna praksa', 'za praksu u opštini', 'prakse za mlade', 'Pripravnički staž', 'pripravnici'],
            'stipendija' => ['Stipendija za studente', 'Konkurs za stipendije', 'stipendista fonda', 'stipendiju dobija'],
            'konkurs' => ['Konkurs za prijavu', 'Javni poziv za podnošenje projekata', 'poziv za prijavu', 'Rezultati konkursa', 'pozivi organizacijama'],
            'obuka' => ['Besplatna obuka', 'kurs engleskog jezika', 'Kursevi za mlade', 'radionica o pisanju CV-a', 'trening veština', 'na obuci'],
        ]);
    }

    #[Test]
    #[DataProvider('saRecju')]
    public function rec_vrste_ili_srodna_rec_sa_strane_dokazuje_vrstu(VrstaPrilike $vrsta, string $tekst): void
    {
        $this->assertTrue($vrsta->potkrepljenaTekstom($tekst), $tekst);
    }

    /** @return array<string, array{0: VrstaPrilike, 1: string}> */
    public static function bezReci(): array
    {
        return self::skup([
            'posao' => ['Poslovanje preduzeća', 'poslovni prostor', 'Rezultati odabira', 'poslovica'],
            'praksa' => ['Praktične veštine', 'praktikum', 'Rezultati'],
            'stipendija' => ['Nagrada za najbolje', 'EU grantovi do 20.000 evra za pravnu pomoć', 'dotacije'],
            'konkurs' => ['Pozivamo vas na vebinar', 'pozivnica za događaj', 'EU grantovi do 20.000 evra'],
            'obuka' => ['Kursna lista banke', 'kursor miša', 'Predavanje o pravima'],
        ]);
    }

    // Ogledalo: reč koja samo liči (pozivamo, kursna lista, poslovanje) ne dokazuje vrstu.
    #[Test]
    #[DataProvider('bezReci')]
    public function slicna_rec_ne_dokazuje_vrstu(VrstaPrilike $vrsta, string $tekst): void
    {
        $this->assertFalse($vrsta->potkrepljenaTekstom($tekst), $tekst);
    }

    #[Test]
    public function svaka_vrsta_sa_recju_ima_primere_i_u_jednom_i_u_drugom_smeru(): void
    {
        foreach (array_filter(VrstaPrilike::cases(), fn (VrstaPrilike $vrsta) => $vrsta !== VrstaPrilike::Drugo) as $vrsta) {
            $this->assertNotEmpty(array_filter(self::saRecju(), fn (array $primer) => $primer[0] === $vrsta), $vrsta->value);
            $this->assertNotEmpty(array_filter(self::bezReci(), fn (array $primer) => $primer[0] === $vrsta), $vrsta->value);
        }
    }

    // „Drugo" nema svoju reč, pa se ne dokazuje ničim, ni rečima drugih vrsta.
    #[Test]
    public function drugo_se_ne_moze_dokazati(): void
    {
        $this->assertFalse(VrstaPrilike::Drugo->potkrepljenaTekstom('posao praksa stipendija konkurs obuka drugo'));
        $this->assertFalse(VrstaPrilike::Drugo->potkrepljenaTekstom(''));
    }

    // Reč druge vrste ne dokazuje ovu: „stipendija" na strani ne dokazuje „posao".
    #[Test]
    public function rec_druge_vrste_ne_dokazuje_ovu_vrstu(): void
    {
        $this->assertTrue(VrstaPrilike::Stipendija->potkrepljenaTekstom('Konkurs za stipendije'));
        $this->assertFalse(VrstaPrilike::Posao->potkrepljenaTekstom('Konkurs za stipendije'));
        $this->assertFalse(VrstaPrilike::Praksa->potkrepljenaTekstom('Konkurs za stipendije'));
        $this->assertFalse(VrstaPrilike::Obuka->potkrepljenaTekstom('Konkurs za stipendije'));
    }
}
