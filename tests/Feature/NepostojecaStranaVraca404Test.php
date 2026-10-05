<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Ne dira bazu, pa prolazi i kad server baze ne radi.
class NepostojecaStranaVraca404Test extends TestCase
{
    #[Test]
    public function nepostojeca_strana_vraca_404(): void
    {
        $this->get('/ovde-nema-nicega')->assertStatus(404);
    }
}
