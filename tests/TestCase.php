<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Proverava se i posle svakog osvežavanja aplikacije, i pre osobina koje pišu u bazu.
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->proveriBazu();
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
