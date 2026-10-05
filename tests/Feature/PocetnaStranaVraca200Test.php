<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PocetnaStranaVraca200Test extends TestCase
{
    #[Test]
    public function pocetna_strana_vraca_200(): void
    {
        $this->get('/')->assertStatus(200);
    }

    // Ogledalo: bez njega bi test prošao i kad bi svaka adresa vraćala 200.
    #[Test]
    public function nepostojeca_strana_vraca_404(): void
    {
        $this->get('/ovde-nema-nicega')->assertStatus(404);
    }
}
