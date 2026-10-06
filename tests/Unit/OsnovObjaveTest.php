<?php

namespace Tests\Unit;

use App\Enums\OsnovObjave;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OsnovObjaveTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function romi(): array
    {
        return [
            'Rom' => ['Rom iz Niša traži posao'], 'Romi' => ['Konkurs za Romi'], 'Roma' => ['Zajednica Roma u Srbiji'],
            'Romima' => ['namenjeno Romima'], 'Romu' => ['pomoć Romu'], 'Romom' => ['sa Romom'], 'Rome' => ['mladi Rome'],
            'Romkinja' => ['mlada Romkinja'], 'Romkinje' => ['stipendije za Romkinje'], 'Romkinjama' => ['Romkinjama je otvoreno'],
            'romski' => ['romski mladi'], 'romska' => ['romska zajednica'], 'romskoj' => ['u romskoj naselju'],
            'velikim slovima' => ['ZA ROMSKU ZAJEDNICU I ROMA'], 'uz interpunkciju' => ['(Romi), Romkinje; Romima!'],
        ];
    }

    #[Test]
    #[DataProvider('romi')]
    public function rec_rom_u_bilo_kom_obliku_potkrepljuje_osnov_romi(string $citat): void
    {
        $this->assertTrue(OsnovObjave::Romi->potkrepljuje($citat));
    }

    /** @return array<string, array{0: string}> */
    public static function nisuRomi(): array
    {
        return [
            'roman' => ['Objavljen je roman'], 'romani' => ['romani za mlade'], 'romantika' => ['romantika u parku'],
            'promocija' => ['promocija programa'], 'Romanija' => ['planina Romanija'], 'bez reči' => ['Konkurs za sve građane'],
            'prazno' => [''], 'Rim' => ['putovanje u Rim'],
        ];
    }

    // Ogledalo: reči koje samo počinju ili sadrže „rom" ne računaju se.
    #[Test]
    #[DataProvider('nisuRomi')]
    public function slicne_reci_ne_potkrepljuju_osnov_romi(string $citat): void
    {
        $this->assertFalse(OsnovObjave::Romi->potkrepljuje($citat));
    }

    /** @return array<string, array{0: string}> */
    public static function srbija(): array
    {
        return [
            'Srbija' => ['Srbija je zemlja'], 'Srbije' => ['građani Srbije'], 'Srbiji' => ['u Srbiji'], 'Srbiju' => ['za Srbiju'],
            'Srbijom' => ['sa Srbijom'], 'malim slovima' => ['u srbiji'], 'velikim slovima' => ['U SRBIJI'],
        ];
    }

    #[Test]
    #[DataProvider('srbija')]
    public function rec_srbija_u_padezima_potkrepljuje_osnov_srbija(string $citat): void
    {
        $this->assertTrue(OsnovObjave::Srbija->potkrepljuje($citat));
    }

    /** @return array<string, array{0: string}> */
    public static function nijeSrbija(): array
    {
        return [
            'srpski' => ['srpski jezik'], 'Srbin' => ['Srbin iz Niša'], 'srbijanski' => ['srbijanski proizvodi'],
            'Beograd' => ['u Beogradu'], 'prazno' => [''],
        ];
    }

    #[Test]
    #[DataProvider('nijeSrbija')]
    public function druge_reci_ne_potkrepljuju_osnov_srbija(string $citat): void
    {
        $this->assertFalse(OsnovObjave::Srbija->potkrepljuje($citat));
    }

    // Osnovi se ne mešaju: „Romi" ne prolazi na reč Srbija i obrnuto.
    #[Test]
    public function osnov_priznaje_samo_svoju_rec(): void
    {
        $this->assertFalse(OsnovObjave::Romi->potkrepljuje('građani Srbije'));
        $this->assertFalse(OsnovObjave::Srbija->potkrepljuje('namenjeno Romima'));
    }
}
