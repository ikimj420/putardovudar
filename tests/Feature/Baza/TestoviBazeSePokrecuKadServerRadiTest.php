<?php

namespace Tests\Feature\Baza;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\PokreceDeteProces;

#[Group('baza')]
#[Group('podproces')]
class TestoviBazeSePokrecuKadServerRadiTest extends BazaTestCase
{
    use PokreceDeteProces;

    // Ogledalo: da se testovi baze preskaču uvek, prethodna tvrdnja bi bila zelena a baza ne bi bila merena.
    #[Test]
    public function sa_serverom_testovi_baze_se_izvrsavaju_a_ne_preskacu(): void
    {
        [$kod, $dnevnik] = $this->pokreniPhpunit(['--group', 'baza', '--exclude-group', 'podproces'], []);

        $this->assertSame(0, $kod);
        $this->assertStringNotContainsString('Test Skipped', $dnevnik);
        $this->assertGreaterThan(0, preg_match_all('/^Test Passed \(Tests\\\\Feature\\\\Baza\\\\/m', $dnevnik));
    }
}
