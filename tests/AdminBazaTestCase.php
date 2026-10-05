<?php

namespace Tests;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenjaOkruzenje;

// Panel postoji samo uz ADMIN_PATH, pa ga test zadaje sam i ne zavisi od Ivanovog .env.
abstract class AdminBazaTestCase extends BazaTestCase
{
    use MenjaOkruzenje, RefreshDatabase;

    protected function setUp(): void
    {
        $this->postaviOkruzenje('ADMIN_PATH', 'a'.bin2hex(random_bytes(6)));

        parent::setUp();

        Filament::setCurrentPanel('admin');
    }
}
