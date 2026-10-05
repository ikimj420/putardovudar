<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UvozIzvoriUPodesavanjimaTest extends TestCase
{
    #[Test]
    public function podesavanja_nabrajaju_tacno_pet_provernih_izvora(): void
    {
        $this->assertSame([
            ['ime' => 'Fond za mlade talente', 'adresa' => 'https://fondzamladetalente.gov.rs/feed/'],
            ['ime' => 'Erasmus+ Srbija', 'adresa' => 'https://erasmusplus.rs/feed/'],
            ['ime' => 'EU mogućnosti - konkursi', 'adresa' => 'https://eumogucnosti.rs/category/konkursi/feed/'],
            ['ime' => 'Youth.rs', 'adresa' => 'https://youth.rs/feed/'],
            ['ime' => 'EU u Srbiji - konkursi', 'adresa' => 'https://europa.rs/category/konkursi/feed/'],
        ], config('uvoz.izvori'));
    }

    // Ogledalo: svaki izvor ima ime i veb adresu, a imena i adrese se ne ponavljaju.
    #[Test]
    public function svaki_izvor_ima_ime_i_https_adresu_a_nijedan_se_ne_ponavlja(): void
    {
        $izvori = config('uvoz.izvori');

        $this->assertNotSame([], $izvori);

        foreach ($izvori as $izvor) {
            $this->assertNotSame('', trim((string) ($izvor['ime'] ?? '')));
            $this->assertMatchesRegularExpression('#^https://[^\s/]+/\S*$#', (string) ($izvor['adresa'] ?? ''));
            $this->assertNotFalse(filter_var($izvor['adresa'], FILTER_VALIDATE_URL));
        }

        $this->assertSame(count($izvori), count(array_unique(array_column($izvori, 'ime'))));
        $this->assertSame(count($izvori), count(array_unique(array_column($izvori, 'adresa'))));
    }

    #[Test]
    public function ogranicenja_uvoza_su_u_podesavanjima(): void
    {
        $this->assertSame(50, config('uvoz.najvise_stavki'));
        $this->assertSame(30, config('uvoz.vreme_cekanja'));
        $this->assertSame(2_000_000, config('uvoz.najvise_bajtova'));
        $this->assertNotSame('', config('uvoz.korisnicki_agent'));
    }
}
