<?php

namespace Tests\Feature\Baza;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Početna čita bazu, pa test ide u grupu baza; 404 za nepostojeću stranu je u NepostojecaStranaVraca404Test.
#[Group('baza')]
class PocetnaStranaVraca200Test extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function pocetna_strana_vraca_200(): void
    {
        $this->get('/')->assertStatus(200);
    }

    // Ogledalo: bez njega bi test prošao i kad bi svaka adresa vraćala 200.
    #[Test]
    public function nepostojeca_strana_prilike_vraca_404(): void
    {
        $this->get('/prilike/ovde-nema-nicega')->assertStatus(404);
    }
}
