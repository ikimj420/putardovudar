<?php

namespace Tests\Podrska;

use App\Services\RazresivacImena;

// Test ne ide na pravi DNS: svako ime se razrešava na javnu adresu, osim „localhost" i onih koje test izričito usmeri drugde (petlja, privatna mreža, ništa).
final class LazniRazresivac implements RazresivacImena
{
    public const JAVNA_ADRESA = '93.184.216.34';

    /** @var array<string, list<string>> */
    private array $zapisi = ['localhost' => ['127.0.0.1']];

    /** @param  list<string>  $adrese */
    public function postavi(string $ime, array $adrese): void
    {
        $this->zapisi[strtolower($ime)] = $adrese;
    }

    public function adrese(string $ime): array
    {
        return $this->zapisi[strtolower($ime)] ?? [self::JAVNA_ADRESA];
    }
}
