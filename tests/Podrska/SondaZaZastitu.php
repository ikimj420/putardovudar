<?php

namespace Tests\Podrska;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Nije u skupu testova (ime ne završava na Test): pušta se samo iz posebnog procesa, uvek sa mrtvim portom.
class SondaZaZastitu extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sonda_stize_do_tela_testa(): void
    {
        $this->assertTrue(true);
    }
}
