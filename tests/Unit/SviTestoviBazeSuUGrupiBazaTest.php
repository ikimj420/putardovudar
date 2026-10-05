<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\BazaTestCase;

class SviTestoviBazeSuUGrupiBazaTest extends TestCase
{
    #[Test]
    public function svaka_potklasa_bazne_klase_za_bazu_nosi_grupu_baza(): void
    {
        $nadjeno = [];
        $bezGrupe = [];

        foreach (glob(dirname(__DIR__).'/{Feature,Feature/*,Unit}/*Test.php', GLOB_BRACE) as $fajl) {
            $klasa = 'Tests\\'.str_replace('/', '\\', substr($fajl, strlen(dirname(__DIR__)) + 1, -4));
            $refleksija = new ReflectionClass($klasa);

            if (! $refleksija->isSubclassOf(BazaTestCase::class)) {
                continue;
            }

            $nadjeno[] = $klasa;

            $grupe = array_map(fn ($atribut) => $atribut->newInstance()->name(), $refleksija->getAttributes(Group::class));

            if (! in_array('baza', $grupe, true)) {
                $bezGrupe[] = $klasa;
            }
        }

        $this->assertNotSame([], $nadjeno, 'Merilo nije obišlo nijednu klasu baze.');
        $this->assertSame([], $bezGrupe);
    }
}
