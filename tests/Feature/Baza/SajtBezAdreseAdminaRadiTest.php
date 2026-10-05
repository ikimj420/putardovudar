<?php

namespace Tests\Feature\Baza;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\Feature\AdresaAdminPaneIzOkruzenjaTest;

// Početna čita bazu, pa ovaj test ide u grupu baza; adresa panela ostaje izolovana od lokalnog .env.
#[Group('baza')]
class SajtBezAdreseAdminaRadiTest extends BazaTestCase
{
    use BezLokalnogOkruzenja, RefreshDatabase;

    protected function setUp(): void
    {
        $this->uzmiVezuKaBaziIzEnv();

        parent::setUp();
    }

    // Ogledalo: sajt bez panela i dalje radi, pa 404 za /login nije posledica pokvarene aplikacije.
    #[Test]
    #[DataProviderExternal(AdresaAdminPaneIzOkruzenjaTest::class, 'adreseBezVrednosti')]
    public function adresa_bez_vrednosti_ne_rusi_sajt(?string $vrednost): void
    {
        $this->postaviOkruzenje('ADMIN_PATH', $vrednost);
        $this->refreshApplication();

        $this->get('/')->assertStatus(200);
    }
}
