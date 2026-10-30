<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\TekstoviZaPosetioca;

// Paket 52: tekstovi za posetioca su jednostavni: rečenica ima najviše 15 reči i nema stranih reči sa spiska. Svaki nalaz koji je Ivan prihvatio
// do odluke stoji u IZUZECI; novi tekst sa dugom rečenicom ili stranom rečju obara test, a izuzetak koji više ne treba obara ga takođe.
class JednostavanJezikTest extends TestCase
{
    use TekstoviZaPosetioca;

    // Tekst => razlozi. Predlog kraćeg teksta je u PITANJA-46-52.md (paket 52); kad Ivan odobri, izmena je nov paket i izuzetak se briše.
    private const IZUZECI = [
        'Online' => ['strana reč: online'],
        'Brzi filteri' => ['strana reč: filteri'],
    ];

    /** @return array<string, list<string>> tekst => razlozi, za svaki tekst koji nije jednostavan */
    private function nalazi(): array
    {
        $nalazi = [];

        foreach ($this->tekstoviZaPosetioca() as $tekst) {
            $razlozi = $this->razloziZaNalaz($tekst['tekst']);

            if ($razlozi !== []) {
                $nalazi[$tekst['tekst']] = $razlozi;
            }
        }

        return $nalazi;
    }

    // Merilo prvo dokazuje da je obišlo tekstove iz oba izvora, pa tek onda da ništa novo nije našlo.
    #[Test]
    public function merilo_obilazi_konstante_i_javne_strane(): void
    {
        $tekstovi = $this->tekstoviZaPosetioca();
        $izvori = array_map(fn (array $tekst) => $tekst['izvor'], $tekstovi);
        $sviTekstovi = array_column($tekstovi, 'tekst');

        $this->assertGreaterThan(60, count($tekstovi));
        $this->assertNotEmpty(array_filter($izvori, fn (string $izvor) => str_starts_with($izvor, 'app/Support/Prikaz')));
        $this->assertNotEmpty(array_filter($izvori, fn (string $izvor) => str_starts_with($izvor, 'resources/views/javno/')));
        $this->assertContains('Pre prijave proveri podatke na zvaničnom izvoru.', $sviTekstovi);
        $this->assertContains('Pošalji komšiji', $sviTekstovi);
    }

    #[Test]
    public function svaki_nalaz_je_na_spisku_izuzetaka_a_svaki_izuzetak_jos_treba(): void
    {
        $nalazi = $this->nalazi();

        $this->assertSame([], array_diff_key($nalazi, self::IZUZECI), 'Novi tekst koji nije jednostavan (kraća rečenica ili reč koju svako zna) ili izuzetak sa predlogom u PITANJA-46-52.md.');
        $this->assertSame([], array_diff_key(self::IZUZECI, $nalazi), 'Izuzetak koji više ne treba: tekst je promenjen ili uklonjen, izuzetak se briše.');

        foreach (self::IZUZECI as $tekst => $razlozi) {
            $this->assertSame($razlozi, $nalazi[$tekst], $tekst);
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function primeriMerila(): array
    {
        $petnaest = 'jedan dva tri četiri pet šest sedam osam devet deset jedanaest dvanaest trinaest četrnaest petnaest.';

        return [
            'tačno petnaest reči prolazi' => [$petnaest, []],
            'šesnaest reči ne prolazi' => ['Ovde piše '.$petnaest, ['duga rečenica (17 reči)']],
            'dve kratke rečenice prolaze' => ['Prva rečenica ima malo reči. Druga rečenica takođe ima malo reči.', []],
            'duga rečenica posle kratke' => ['Kratko je. '.$petnaest.' I još ovo!', []],
            'strana reč' => ['Ostavi feed ovde.', ['strana reč: feed']],
            'strana reč velikim slovom i padež' => ['Brzi Filteri i Klikovi.', ['strana reč: filteri', 'strana reč: klikovi']],
            'link i linkovi nisu strane reči (Ivan, 30.10.2026.)' => ['Ostavi link ili Linkove ovde.', []],
            'mejl' => ['Pošalji mejl.', ['strana reč: mejl']],
            'e-mail' => ['Napiši E-mail.', ['strana reč: e-mail']],
            'Viber i WhatsApp su imena' => ['Pošalji komšiji preko Vibera ili WhatsApp.', []],
            'e-pošta je u redu' => ['E-pošta', []],
            'reč koja samo počinje kao strana' => ['Linije označene crtama popuni olovkom.', []],
        ];
    }

    /** @param  list<string>  $razlozi */
    #[Test]
    #[DataProvider('primeriMerila')]
    public function merilo_prepoznaje_duge_recenice_i_strane_reci_a_ne_obicne_tekstove(string $tekst, array $razlozi): void
    {
        $this->assertSame($razlozi, $this->razloziZaNalaz($tekst), $tekst);
    }
}
