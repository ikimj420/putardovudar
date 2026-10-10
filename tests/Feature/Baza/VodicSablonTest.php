<?php

namespace Tests\Feature\Baza;

use App\Models\Sablon;
use App\Models\Vodic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 56 T2: koji šablon vodič nosi i kada je on javan; odluka o javnosti ostaje u Sablon::javni().
#[Group('baza')]
class VodicSablonTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function objavljen_sablon_je_javan_sablon_vodica(): void
    {
        $sablon = Sablon::factory()->objavljen()->create();
        $vodic = Vodic::factory()->objavljen()->create(['sablon_id' => $sablon->getKey()]);

        $this->assertTrue($vodic->javniSablon()?->is($sablon));
        $this->assertTrue($vodic->sablon->is($sablon));
    }

    // Ogledalo: nacrt šablona, vodič bez šablona i obrisan šablon ne daju javan šablon.
    #[Test]
    public function nacrt_bez_sablona_i_obrisan_sablon_ne_daju_javan_sablon(): void
    {
        $nacrt = Sablon::factory()->create();
        $zaBrisanje = Sablon::factory()->objavljen()->create();

        $sNacrtom = Vodic::factory()->objavljen()->create(['sablon_id' => $nacrt->getKey()]);
        $bez = Vodic::factory()->objavljen()->create(['sablon_id' => null]);
        $sObrisanim = Vodic::factory()->objavljen()->create(['sablon_id' => $zaBrisanje->getKey()]);

        $this->assertNull($sNacrtom->javniSablon());
        $this->assertNull($bez->javniSablon());
        $this->assertTrue($sObrisanim->javniSablon()?->is($zaBrisanje), 'merilo mora da vidi šablon pre brisanja');

        $zaBrisanje->delete();

        $this->assertNull($sObrisanim->fresh()->sablon_id);
        $this->assertNull($sObrisanim->fresh()->javniSablon());
    }
}
