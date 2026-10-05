<?php

namespace Tests\Concerns;

use Dotenv\Dotenv;
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

    // Veza ka bazi nije predmet merenja, pa se uzima iz lokalnog .env, osim ako je proces već zadaje (npr. mrtav port u probi).
    protected function uzmiVezuKaBaziIzEnv(): void
    {
        $putanja = dirname(__DIR__, 2).'/.env';
        $vrednosti = is_file($putanja) ? Dotenv::parse((string) file_get_contents($putanja)) : [];

        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $naziv) {
            if (isset($vrednosti[$naziv]) && getenv($naziv) === false) {
                $this->postaviOkruzenje($naziv, $vrednosti[$naziv]);
            }
        }
    }
}
