<?php

namespace Tests\Unit;

use App\Enums\Grupa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 40: citat potkrepljuje grupu samo reči iz njenog rečnika. Rečnik je uzak (firme nikad nisu „organizacije"),
// a svaka grupa ima primere koji prolaze i primere koji liče, a ne prolaze.
class GrupaTest extends TestCase
{
    /** @return array<string, array{0: Grupa, 1: string}> */
    public static function prolaze(): array
    {
        $primeri = [
            'osnovci' => ['Konkurs za osnovce', 'Otvoreno za učenike osnovnih škola', 'osnovnoškolci iz cele Srbije', 'Učenici osmog razreda osnovne škole mogu da se prijave', 'Za svakog osnovca', 'ĐACI OSNOVNIH ŠKOLA', 'Nagrade za 200 osnovaca', 'Poziv za đaka osnovne škole'],
            'srednjoskolci' => ['Za srednjoškolce', 'Učenici srednjih škola', 'gimnazijalci i maturanti', 'Poziv gimnazijalcima', 'za maturante', 'Poziv srednjoškolkama i srednjoškolcima', 'učenicima trećeg razreda srednje škole', 'Za 50 gimnazijalaca', 'Nagrade za najbolje maturanata'],
            'studenti' => ['Studenti prve godine', 'za studentkinje', 'Otvoreno studentima', 'Stipendije za studente iz Srbije', 'Stipendije za 100 studenata'],
            'master-doktorski' => ['Master studije u inostranstvu', 'za doktorske studije', 'doktoranti i doktorandi', 'Poziv doktorandima', 'Za 20 doktoranada', 'postdiplomske studije', 'magistarske studije', 'PhD kandidati', 'studenti mastera'],
            'nezaposleni-mladi' => ['Nezaposleni mladi do 30 godina', 'za nezaposlena lica', 'mladi bez posla', 'osobe bez zaposlenja', 'Tražioci posla'],
            'povratnici' => ['Povratnici iz inostranstva', 'Povratnici po osnovu sporazuma o readmisiji', 'povratnik u Srbiju', 'Povratak u Srbiju', 'vraćeni iz inostranstva', 'Osobe po sporazumu o readmisiji', 'repatrijaci'],
            'organizacije' => ['Konkurs za organizacije civilnog društva', 'Udruženja građana', 'nevladine organizacije', 'NVO sektor', 'OCD iz cele Srbije', 'neprofitne organizacije', 'Podrška organizacijama koje rade sa mladima', 'organizacije mladih', 'civilni sektor'],
            'romi' => ['Rom iz Niša traži posao', 'Konkurs za Romi', 'Zajednica Roma u Srbiji', 'namenjeno Romima', 'pomoć Romu', 'sa Romom', 'mladi Rome', 'mlada Romkinja', 'stipendije za Romkinje', 'Romkinjama je otvoreno', 'romski mladi', 'romska zajednica', 'u romskoj naselju', 'ZA ROMSKU ZAJEDNICU I ROMA', '(Romi), Romkinje; Romima!', 'РОМИ И РОМКИЊЕ'],
        ];
        $skup = [];

        foreach ($primeri as $vrednost => $citati) {
            foreach ($citati as $citat) {
                $skup[$vrednost.': '.$citat] = [Grupa::from($vrednost), $citat];
            }
        }

        return $skup;
    }

    #[Test]
    #[DataProvider('prolaze')]
    public function citat_sa_recju_grupe_potkrepljuje_grupu(Grupa $grupa, string $citat): void
    {
        $this->assertTrue($grupa->potkrepljuje($citat), $citat);
    }

    /** @return array<string, array{0: Grupa, 1: string}> */
    public static function lice(): array
    {
        $primeri = [
            'osnovci' => ['Osnovna škola traži nastavnika', 'Osnove programiranja', 'Osnovni kurs engleskog', 'nastavnici osnovnih škola', 'osnovica poreza', 'Dakle, osnovna škola traži nastavnika'],
            'srednjoskolci' => ['Srednja klasa', 'Srednji nivo znanja', 'nastavnici srednjih škola', 'srednjoročni plan', 'Dakle srednja klasa'],
            'studenti' => ['Studio za dizajn', 'studije u inostranstvu', 'Stud. servis', 'istraživači'],
            'master-doktorski' => ['Mastercard popust', 'Masterplan grada', 'doktor opšte prakse', 'majstor za vodovod', 'Mastering Excel'],
            'nezaposleni-mladi' => ['Zaposleni u firmi', 'zaposlenje u Nišu', 'Posao za mlade', 'mladi u zajednici'],
            'povratnici' => ['Povratna informacija', 'povrat sredstava', 'povratni let', 'iz inostranstva stiže pomoć'],
            'organizacije' => ['Firme i preduzeća', 'privredna društva', 'organizacija događaja', 'međunarodne organizacije', 'Za organizacije', 'društvo sa ograničenom odgovornošću', 'kompanije iz Srbije'],
            'romi' => ['Objavljen je roman', 'romani za mlade', 'romantika u parku', 'promocija programa', 'planina Romanija', 'putovanje u Rim', 'Konkurs za sve građane'],
        ];
        $skup = [];

        foreach ($primeri as $vrednost => $citati) {
            foreach ($citati as $citat) {
                $skup[$vrednost.': '.$citat] = [Grupa::from($vrednost), $citat];
            }
        }

        return $skup;
    }

    // Ogledalo: reči koje samo liče na reč grupe, ili tuđe grupe, ne potkrepljuju ovu grupu.
    #[Test]
    #[DataProvider('lice')]
    public function slicna_rec_ne_potkrepljuje_grupu(Grupa $grupa, string $citat): void
    {
        $this->assertFalse($grupa->potkrepljuje($citat), $citat);
    }

    #[Test]
    public function svaka_grupa_ima_primere_koji_prolaze_i_primere_koji_ne_prolaze(): void
    {
        foreach (Grupa::cases() as $grupa) {
            $this->assertNotEmpty(array_filter(self::prolaze(), fn (array $primer) => $primer[0] === $grupa), $grupa->value.' nema primere koji prolaze');
            $this->assertNotEmpty(array_filter(self::lice(), fn (array $primer) => $primer[0] === $grupa), $grupa->value.' nema primere koji ne prolaze');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function firmeISrbija(): array
    {
        return [
            'firme' => ['Konkurs je otvoren za firme'], 'firme i preduzeća' => ['Poziv za firme i preduzeća'], 'privredna društva' => ['Za privredna društva u Srbiji'],
            'kompanije' => ['Kompanije koje zapošljavaju'], 'samo Srbija' => ['Konkurs je otvoren za sve građane Srbije'], 'u Srbiji' => ['dostupno u Srbiji'],
            'prazno' => [''],
        ];
    }

    // Firme nisu na spisku, a reč „Srbija" više ne dokazuje ništa: nijedna grupa se ne priznaje.
    #[Test]
    #[DataProvider('firmeISrbija')]
    public function firme_i_sama_srbija_ne_potkrepljuju_nijednu_grupu(string $citat): void
    {
        foreach (Grupa::cases() as $grupa) {
            $this->assertFalse($grupa->potkrepljuje($citat), $grupa->value.': '.$citat);
        }

        $this->assertSame([], Grupa::izTeksta($citat));
    }

    #[Test]
    public function iz_teksta_vraca_grupe_u_redosledu_enuma(): void
    {
        $this->assertSame([Grupa::Studenti, Grupa::NezaposleniMladi, Grupa::Romi], Grupa::izTeksta('Romi i nezaposleni mladi, kao i studenti'));
        $this->assertSame([Grupa::Studenti, Grupa::MasterDoktorski], Grupa::izTeksta('studenti master studija'));
        $this->assertSame([], Grupa::izTeksta('Konkurs za firme'));
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function ostaciFraza(): array
    {
        return [
            'ime sa rečju grupe' => ['Opre Roma', ['opre']],
            'predlog na početku ispada' => ['za studente u tehnici', ['tehnici']],
            'predlog na kraju ispada' => ['tehnici za studente', ['tehnici']],
            'samo grupa' => ['studente', []],
            'dve reči grupe' => ['mladi Romi', ['mladi']],
            'bez reči grupe' => ['skladištu', ['skladistu']],
            'fraza grupe od više reči' => ['organizacije mladih', []],
            'reč grupe u sredini deli frazu' => ['Opre Roma u zajednici', ['opre', 'zajednici']],
        ];
    }

    /** @param  list<string>  $ostatak */
    #[Test]
    #[DataProvider('ostaciFraza')]
    public function bez_reci_grupe_ostavlja_ono_sto_pitanje_jos_trazi(string $fraza, array $ostatak): void
    {
        $this->assertSame($ostatak, Grupa::bezReciGrupe($fraza), $fraza);
    }
}
