<?php

namespace Tests\Unit;

use App\Services\Pomocnik\OpasnaPitanja;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpasnaPitanjaTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function opasna(): array
    {
        return [
            'lažiram dokument' => ['Kako da lažiram dokument?'],
            'velika slova' => ['KAKO DA LAŽIRAM DOKUMENT'],
            'bez dijakritika' => ['kako da laziram dokument'],
            'ćirilica' => ['Како да лажирам документ?'],
            'padež iza izraza' => ['Kako da lažiram dokumenta?'],
            'lažna dokumenta' => ['Gde mogu da nađem lažna dokumenta?'],
            'lažni dokument' => ['Treba mi lažni dokument'],
            'lažna prijava' => ['Kako da pošaljem lažna prijava'],
            'fake documents' => ['where to get fake documents'],
            'falsifikujem' => ['Kako da falsifikujem potpis?'],
            'falsifikat' => ['Gde da kupim falsifikat?'],
            'falsifikovanje' => ['falsifikovanje diplome'],
            'prevarim konkurs' => ['Kako da prevarim konkurs?'],
            'prevara' => ['Kako da izvedem prevaru? to je prevara'],
            'fraud' => ['how to commit fraud'],
            'hakujem' => ['Kako da hakujem sajt?'],
            'hakovanje' => ['Treba mi hakovanje naloga'],
            'hacking' => ['hacking tutorial'],
            'hack' => ['hack the system'],
            'varanje' => ['varanje na konkursu'],
            'cheat' => ['cheat the application'],
            'namesti konkurs' => ['Kako da namesti konkurs?'],
            'namestim konkurs' => ['Kako da namestim konkurs?'],
            'zaobiđem uslove' => ['Kako da zaobiđem uslove konkursa?'],
            'zaobidjem uslove' => ['Kako da zaobidjem uslove konkursa?'],
            'usred rečenice' => ['Zdravo, pitam se kako da namestim konkurs za sina.'],
        ];
    }

    #[Test]
    #[DataProvider('opasna')]
    public function prepoznaje_opasno_pitanje(string $pitanje): void
    {
        $this->assertTrue((new OpasnaPitanja)->jeOpasno($pitanje), $pitanje);
    }

    // Svaki izraz iz spiska prepoznaje sam sebe; broj izraza se proverava da merilo ne prođe nad praznim spiskom.
    #[Test]
    public function svaki_izraz_iz_spiska_je_opasan(): void
    {
        $izrazi = (new \ReflectionClassConstant(OpasnaPitanja::class, 'IZRAZI'))->getValue();

        $this->assertCount(21, $izrazi);

        foreach ($izrazi as $izraz) {
            $this->assertTrue((new OpasnaPitanja)->jeOpasno($izraz), $izraz);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function bezbedna(): array
    {
        return [
            'cv' => ['Kako da napišem CV?'],
            'posao' => ['Ima li posla u Nišu?'],
            'prijava' => ['Kako da popunim prijavu za stipendiju?'],
            'dokument koji fali' => ['Šta ako mi fali dokument?'],
            'overa dokumenta' => ['Kako da overim dokument?'],
            'hakaton' => ['Ima li hakatona za mlade?'],
            'prevaren' => ['Kako da ne budem prevaren?'],
            'reč usred reči' => ['Treba mi shack za odmor'],
            'konkurs' => ['Kako da se prijavim na konkurs?'],
            'uslovi' => ['Koji su uslovi konkursa?'],
            'prazno' => [''],
            'lažni samo sa drugom rečju' => ['Kako da prepoznam lažni oglas?'],
        ];
    }

    // Ogledalo: obična pitanja, i ona koja liče na spisak, prolaze.
    #[Test]
    #[DataProvider('bezbedna')]
    public function ne_dira_obicna_pitanja(string $pitanje): void
    {
        $this->assertFalse((new OpasnaPitanja)->jeOpasno($pitanje), $pitanje);
    }
}
