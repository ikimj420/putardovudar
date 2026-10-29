<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UvozIzvoriUPodesavanjimaTest extends TestCase
{
    #[Test]
    public function podesavanja_nabrajaju_tacno_sesnaest_provernih_izvora(): void
    {
        $this->assertSame([
            ['ime' => 'Fond za mlade talente', 'adresa' => 'https://fondzamladetalente.gov.rs/feed/'],
            ['ime' => 'Erasmus+ Srbija', 'adresa' => 'https://erasmusplus.rs/feed/'],
            ['ime' => 'EU mogućnosti - konkursi', 'adresa' => 'https://eumogucnosti.rs/category/konkursi/feed/'],
            ['ime' => 'Youth.rs', 'adresa' => 'https://youth.rs/feed/'],
            ['ime' => 'KOMS - konkursi', 'adresa' => 'https://koms.rs/category/konkursi/feed/'],
            ['ime' => 'Ministarstvo prosvete', 'adresa' => 'https://prosveta.gov.rs/kategorija/vazna-obavestenja/feed/'],
            ['ime' => 'Ministarstvo za ljudska i manjinska prava', 'adresa' => 'https://minljmpdd.gov.rs/category/konkursi-i-javni-pozivi-2026/feed/'],
            ['ime' => 'Infostud Poslovi', 'adresa' => 'https://rss.infostud.com/poslovi/rss.php', 'najvise_strana' => 1],
            ['ime' => 'Forum Roma Srbije', 'adresa' => 'https://www.frs.org.rs/feed/'],
            ['ime' => 'Kancelarija za inkluziju Roma', 'adresa' => 'https://inkluzijaromavojvodina.rs/feed/'],
            ['ime' => 'Romska ženska mreža', 'adresa' => 'https://rzm.rs/feed/'],
            ['ime' => 'Bibija', 'adresa' => 'https://www.bibija.org.rs/feed/'],
            ['ime' => 'Rromanipen', 'adresa' => 'https://www.romanipen.org.rs/feed/'],
            ['ime' => 'ROSA', 'adresa' => 'https://rosale.org/feed/'],
            ['ime' => 'Osvit', 'adresa' => 'https://osvit.rs/feed/'],
            ['ime' => 'Udruženje Roma Novi Bečej', 'adresa' => 'https://udruzenjeromanb.org.rs/vesti?format=feed&type=rss', 'najvise_strana' => 1],
        ], config('uvoz.izvori'));
        $this->assertCount(16, config('uvoz.izvori'));
        // Paket 74/T2: europa.rs nema feed konkursa (adresa daje RSS bez stavki), pa nije među izvorima.
        $this->assertNotContains('EU u Srbiji - konkursi', array_column(config('uvoz.izvori'), 'ime'));
        // Paket 78: Opre Roma Srbija nema RSS (adrese /feed i /rss vode na početnu stranu u HTML-u), pa nije među izvorima; ERIAC i REF su na engleskom.
        foreach (['Opre Roma Srbija', 'ERIAC', 'Roma Education Fund'] as $ime) {
            $this->assertNotContains($ime, array_column(config('uvoz.izvori'), 'ime'));
        }
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

    // Paket 74/T1 i 78/T1: pet strana po izvoru je opšta granica (jedno mesto), a samo Infostud i Novi Bečej (Joomla), koji vraćaju istu stranu na svakom ?paged=, imaju svoju (jednu).
    #[Test]
    public function strane_po_izvoru_su_u_podesavanjima_a_samo_infostud_i_novi_becej_imaju_svoju_granicu(): void
    {
        $this->assertSame(5, config('uvoz.najvise_strana'));

        $svoje = [];
        foreach (config('uvoz.izvori') as $izvor) {
            if (array_key_exists('najvise_strana', $izvor)) {
                $svoje[$izvor['ime']] = $izvor['najvise_strana'];
            }
        }

        $this->assertSame(['Infostud Poslovi' => 1, 'Udruženje Roma Novi Bečej' => 1], $svoje);
        // Ogledalo: ostali izvori (neprazan skup) koriste opštu granicu.
        $this->assertGreaterThan(1, count(config('uvoz.izvori')));
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
