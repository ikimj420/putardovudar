<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdresaAdminPaneIzOkruzenjaTest extends TestCase
{
    private const NAZIV = 'ADMIN_PATH';

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $sacuvano = [];

    // Lokalni .env se ne učitava: test meri kod, ne podešavanje ove mašine. Zato ključ aplikacije stiže odavde.
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.ne-postoji');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        return $app;
    }

    protected function setUp(): void
    {
        $this->sacuvano = [self::NAZIV => [getenv(self::NAZIV), $_ENV[self::NAZIV] ?? null, $_SERVER[self::NAZIV] ?? null]];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        [$proces, $env, $server] = $this->sacuvano[self::NAZIV];

        $proces === false ? putenv(self::NAZIV) : putenv(self::NAZIV.'='.$proces);
        $env === null ? $this->ukloni($_ENV) : $_ENV[self::NAZIV] = $env;
        $server === null ? $this->ukloni($_SERVER) : $_SERVER[self::NAZIV] = $server;

        parent::tearDown();
    }

    #[Test]
    public function panel_stoji_na_adresi_iz_okruzenja(): void
    {
        $adresa = 'p'.bin2hex(random_bytes(6));

        $this->pokreniSaAdresom($adresa);

        $this->get('/'.$adresa.'/login')->assertStatus(200);
    }

    // Ogledalo: da je 200 vraćan svakoj adresi, prva tvrdnja bi bila zelena a ne bi merila ništa.
    #[Test]
    public function panel_ne_stoji_na_podrazumevanoj_ni_na_tudjoj_adresi(): void
    {
        $adresa = 'p'.bin2hex(random_bytes(6));
        $tudja = 'q'.bin2hex(random_bytes(6));

        $this->pokreniSaAdresom($adresa);

        $this->get('/admin/login')->assertStatus(404);
        $this->get('/'.$tudja.'/login')->assertStatus(404);
    }

    #[Test]
    #[DataProvider('adreseBezVrednosti')]
    public function adresa_bez_vrednosti_ne_stavlja_panel_na_koren_sajta(?string $vrednost): void
    {
        $this->pokreniSaAdresom($vrednost);

        $this->get('/login')->assertStatus(404);
        $this->get('/admin/login')->assertStatus(404);
    }

    // Ogledalo: sajt bez panela i dalje radi, pa 404 gore nije posledica pokvarene aplikacije.
    #[Test]
    #[DataProvider('adreseBezVrednosti')]
    public function adresa_bez_vrednosti_ne_rusi_sajt(?string $vrednost): void
    {
        $this->pokreniSaAdresom($vrednost);

        $this->get('/')->assertStatus(200);
    }

    /** @return array<string, array{0: string|null}> */
    public static function adreseBezVrednosti(): array
    {
        return [
            'prazna' => [''],
            'samo razmaci' => ['   '],
            'samo kosa crta' => ['/'],
            'nepostojeća' => [null],
        ];
    }

    private function pokreniSaAdresom(?string $vrednost): void
    {
        if ($vrednost === null) {
            putenv(self::NAZIV);
            $this->ukloni($_ENV);
            $this->ukloni($_SERVER);
        } else {
            putenv(self::NAZIV.'='.$vrednost);
            $_ENV[self::NAZIV] = $vrednost;
            $_SERVER[self::NAZIV] = $vrednost;
        }

        $this->refreshApplication();
    }

    /** @param  array<string, mixed>  $niz */
    private function ukloni(array &$niz): void
    {
        unset($niz[self::NAZIV]);
    }
}
