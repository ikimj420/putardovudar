<?php

namespace Tests;

use App\Services\RazresivacImena;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Podrska\LazniRazresivac;

abstract class TestCase extends BaseTestCase
{
    private LazniRazresivac $razresivac;

    // Proverava se i posle svakog osvežavanja aplikacije, i pre osobina koje pišu u bazu.
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->proveriBazu();

        // Svaka aplikacija u testu dobija svog razrešivača imena, da proba preuzimanja ne ide na pravi DNS.
        $this->razresivac = new LazniRazresivac;
        $this->app->instance(RazresivacImena::class, $this->razresivac);
    }

    protected function razresivac(): LazniRazresivac
    {
        return $this->razresivac;
    }

    protected function setUpTraits()
    {
        $this->proveriBazu();

        return parent::setUpTraits();
    }

    private function proveriBazu(): void
    {
        ZastitaBaze::proveriVezu(config('database.connections.'.config('database.default')) ?? [], base_path());
    }
}
