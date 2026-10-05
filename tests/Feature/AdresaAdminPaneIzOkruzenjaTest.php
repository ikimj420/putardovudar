<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

class AdresaAdminPaneIzOkruzenjaTest extends TestCase
{
    use BezLokalnogOkruzenja;

    private const NAZIV = 'ADMIN_PATH';

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
        $this->postaviOkruzenje(self::NAZIV, $vrednost);

        $this->refreshApplication();
    }
}
