<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait BezLokalnogOkruzenja
{
    use MenjaOkruzenje;

    // Umesto lokalnog .env učitava se prazan fajl: test meri kod, ne podešavanje ove mašine. Zato ključ aplikacije stiže odavde.
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__);
        $app->loadEnvironmentFrom('prazno.env');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        return $app;
    }
}
