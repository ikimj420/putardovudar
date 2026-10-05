<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SimpleXMLElement;
use Tests\Concerns\PokreceDeteProces;
use Tests\TestCase;

// Server koji ne radi se glumi mrtvim portom 1; DBngin se ne dira.
#[Group('podproces')]
class TestoviBazeSePreskacuKadServerNeRadiTest extends TestCase
{
    use PokreceDeteProces;

    #[Test]
    public function bez_servera_testovi_baze_su_preskoceni_sa_razlogom_a_ostali_prolaze(): void
    {
        [$kod, $dnevnik] = $this->pokreniPhpunit(['--exclude-group', 'podproces'], ['DB_PORT' => '1']);

        $preskoceni = preg_match_all('/^Test Skipped \((.+?)::/m', $dnevnik, $nalazi) ? $nalazi[1] : [];
        $izvanBaze = array_filter($preskoceni, fn (string $klasa) => ! str_starts_with($klasa, 'Tests\\Feature\\Baza\\'));

        $this->assertSame(0, $kod);
        $this->assertNotSame([], $preskoceni, 'Nijedan test baze nije preskočen.');
        $this->assertSame([], array_values($izvanBaze));
        $this->assertStringContainsString('MariaDB nije dostupna na 127.0.0.1:1', $dnevnik);
        $this->assertStringContainsString('Test Passed (Tests\\Feature\\NepostojecaStranaVraca404Test::nepostojeca_strana_vraca_404)', $dnevnik);
        $this->assertStringNotContainsString('Test Errored', $dnevnik);
        $this->assertStringNotContainsString('Test Failed', $dnevnik);
    }

    // Preskakanje bez ispisa razloga ostaje neprimećeno, pa PHPUnit mora da ga prikaže.
    #[Test]
    public function phpunit_ispisuje_razlog_svakog_preskocenog_testa(): void
    {
        $xml = new SimpleXMLElement(file_get_contents(base_path('phpunit.xml')));

        $this->assertSame('true', (string) $xml['displayDetailsOnSkippedTests']);
    }
}
