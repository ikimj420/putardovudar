<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SnimciRssIzvoraSuBezLicnihPodatakaTest extends TestCase
{
    #[Test]
    public function snimci_su_ispravan_rss_bez_autora(): void
    {
        $snimci = glob(dirname(__DIR__).'/Fixtures/rss/*.xml') ?: [];

        // Dokaz da je merilo obišlo neprazan skup: jedan snimak po izvoru.
        $this->assertCount(5, $snimci);

        foreach ($snimci as $snimak) {
            $sadrzaj = (string) file_get_contents($snimak);

            $this->assertNotFalse(simplexml_load_string($sadrzaj), basename($snimak).' nije ispravan XML');
            $this->assertFalse($this->imaAutora($sadrzaj), basename($snimak).' ima autora');
            $this->assertLessThan(10_000, strlen($sadrzaj), basename($snimak));
        }
    }

    // Ogledalo: merilo vidi autora kad ga ima, a ne i kad ga nema.
    #[Test]
    public function merilo_prepoznaje_autora_u_snimku(): void
    {
        $this->assertTrue($this->imaAutora('<item><dc:creator><![CDATA[Ime Prezime]]></dc:creator></item>'));
        $this->assertTrue($this->imaAutora('<item><author>ime@primer.rs</author></item>'));
        $this->assertFalse($this->imaAutora('<item><title>Samo naslov</title></item>'));
    }

    private function imaAutora(string $xml): bool
    {
        return str_contains($xml, 'dc:creator') || str_contains($xml, '<author');
    }
}
