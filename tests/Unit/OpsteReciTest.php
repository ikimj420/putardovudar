<?php

namespace Tests\Unit;

use App\Services\Pomocnik\OpsteReci;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 27: „vodič" i „organizacija" (u svim padežima) ne sužavaju pretragu, a kad su jedine, pitanje je „šta imate".
class OpsteReciTest extends TestCase
{
    /** @return array<string, array{0: list<string>}> */
    public static function samoOpste(): array
    {
        return [
            'vodič' => [['vodič']],
            'vodiče' => [['vodiče']],
            'Vodičima' => [['Vodičima']],
            'organizacija' => [['organizacija']],
            'organizacije' => [['organizacije']],
            'organizacijama' => [['Organizacijama']],
            'obe' => [['vodiči', 'organizacije']],
            'više opštih reči u jednom izrazu' => [['vodiči organizacije']],
            'ćirilica' => [['водичи']],
            'bez dijakritika' => [['vodici']],
        ];
    }

    #[Test]
    #[DataProvider('samoOpste')]
    public function jedine_opste_reci_su_pitanje_sta_imate(array $kljucneReci): void
    {
        $this->assertTrue((new OpsteReci($kljucneReci))->jeSamoOpste(), implode(' + ', $kljucneReci));
    }

    /** @return array<string, array{0: list<string>}> */
    public static function nijeSamoOpste(): array
    {
        return [
            'nema reči' => [[]],
            'samo interpunkcija' => [['?', '!']],
            'uz drugu reč' => [['vodič', 'CV']],
            'druga reč u izrazu' => [['vodič za CV']],
            // Veznik je obična reč: model ključne reči daje kao imenice, pa se veznici ne ignorišu.
            'veznik između opštih reči' => [['vodiči i organizacije']],
            'reč koja samo počinje kao organizacija' => [['organizovanje']],
            'reč koja samo počinje kao vodič' => [['vodovod']],
            'organ' => [['organi']],
        ];
    }

    // Ogledalo: bez reči, uz drugu reč i uz reč koja samo liči na opštu to nije pitanje „šta imate".
    #[Test]
    #[DataProvider('nijeSamoOpste')]
    public function uz_drugu_rec_ili_bez_reci_nije_pitanje_sta_imate(array $kljucneReci): void
    {
        $this->assertFalse((new OpsteReci($kljucneReci))->jeSamoOpste(), implode(' + ', $kljucneReci));
    }

    #[Test]
    public function izrazi_gube_samo_opstu_rec_svoje_vrste(): void
    {
        $reci = new OpsteReci(['vodič za CV', 'organizacije', 'vodiči', 'zapošljavanje']);

        // Predlog „za" otpada iz izraza (pravilo od ispravke paketa 27), a ostale reči ostaju.
        $this->assertSame(['cv', 'organizacije', 'zaposljavanje'], $reci->izrazi(OpsteReci::VODIC));
        $this->assertSame(['vodic cv', 'vodici', 'zaposljavanje'], $reci->izrazi(OpsteReci::ORGANIZACIJA));
        // Ogledalo: reč koja samo počinje slično ostaje.
        $this->assertSame(['organizovanje', 'vodovod'], (new OpsteReci(['organizovanje', 'vodovod']))->izrazi(OpsteReci::ORGANIZACIJA));
        $this->assertSame(['organizovanje', 'vodovod'], (new OpsteReci(['organizovanje', 'vodovod']))->izrazi(OpsteReci::VODIC));
    }

    // Predlog nije tema: „vodič za konkurse" je „vodič" i „konkurse", a spisak predloga je ugovor i stoji ovde doslovno.
    #[Test]
    public function predlog_se_ne_racuna_kao_rec_ni_u_izrazu_ni_sam(): void
    {
        $predlozi = ['za', 'u', 'na', 'o', 'od', 'do', 'po', 'sa', 'uz', 'iz', 'kod', 'pri', 'oko', 'bez', 'preko'];
        $this->assertSame(['konkurse'], (new OpsteReci(['vodič za konkurse']))->izrazi(OpsteReci::VODIC));
        $this->assertSame(['stipendije'], (new OpsteReci(['organizacija za stipendije']))->izrazi(OpsteReci::ORGANIZACIJA));
        $this->assertSame(['zaposljavanje'], (new OpsteReci(['organizacija', 'za zapošljavanje']))->izrazi(OpsteReci::ORGANIZACIJA));
        $this->assertTrue((new OpsteReci(['vodiči za organizacije']))->jeSamoOpste());

        foreach ($predlozi as $predlog) {
            $this->assertSame(['cv'], (new OpsteReci(["vodič {$predlog} CV"]))->izrazi(OpsteReci::VODIC), $predlog);
            $this->assertTrue((new OpsteReci(["vodič {$predlog} organizacije"]))->jeSamoOpste(), $predlog);
            // Ogledalo: sam predlog nije ni opšta reč ni tema.
            $this->assertFalse((new OpsteReci([$predlog]))->jeSamoOpste(), $predlog);
            $this->assertSame([], (new OpsteReci([$predlog]))->izrazi(OpsteReci::VODIC), $predlog);
        }

        // Ogledalo: reč koja samo počinje kao predlog ostaje („zapošljavanje" počinje na „za").
        $this->assertSame(['zaposljavanje'], (new OpsteReci(['zapošljavanje']))->izrazi(OpsteReci::VODIC));
        $this->assertSame(['pozajmica'], (new OpsteReci(['pozajmica']))->izrazi(OpsteReci::VODIC));
    }

    #[Test]
    public function sadrzi_kaze_da_li_ima_reci_te_vrste(): void
    {
        $reci = new OpsteReci(['Vodičima', 'zapošljavanje']);

        $this->assertTrue($reci->sadrzi(OpsteReci::VODIC));
        $this->assertFalse($reci->sadrzi(OpsteReci::ORGANIZACIJA));
        $this->assertFalse((new OpsteReci(['vodovod']))->sadrzi(OpsteReci::VODIC));
        $this->assertFalse((new OpsteReci(['organizovanje']))->sadrzi(OpsteReci::ORGANIZACIJA));
    }
}
