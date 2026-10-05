<?php

namespace Tests\Feature;

use App\Support\Prikaz;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatumIBrojNaSrpskomTest extends TestCase
{
    #[Test]
    public function datum_se_pise_dan_mesec_godina_sa_tackom_na_kraju(): void
    {
        $this->assertSame('05.10.2026.', Prikaz::datum(Carbon::create(2026, 10, 5, 12)));
    }

    // Ogledalo: sistemski oblik ne sme da procuri na ekran.
    #[Test]
    public function datum_se_ne_pise_kao_godina_mesec_dan(): void
    {
        $this->assertStringNotContainsString('2026-10-05', Prikaz::datum(Carbon::create(2026, 10, 5, 12)));
    }

    #[Test]
    public function datum_trenutka_pribijenog_u_testu_pise_se_isto(): void
    {
        $this->travelTo(Carbon::create(2026, 10, 5, 12));

        $this->assertSame('05.10.2026.', Prikaz::datum(now()));
    }

    #[Test]
    public function decimalni_broj_ima_zarez(): void
    {
        $this->assertSame('6,6', Prikaz::broj(6.6, 1));
    }

    // Ogledalo: tačka kao decimalni znak je engleski pravopis.
    #[Test]
    public function decimalni_broj_nema_tacku(): void
    {
        $this->assertStringNotContainsString('.', Prikaz::broj(6.6, 1));
    }

    #[Test]
    public function broj_nosi_onoliko_decimala_koliko_je_trazeno(): void
    {
        $this->assertSame('25,0', Prikaz::broj(25, 1));
        $this->assertSame('7', Prikaz::broj(7.4, 0));
    }
}
