<?php

namespace Tests\Concerns;

trait MenjaOkruzenje
{
    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $okruzenjePre = [];

    // Ranije učitan .env u istom procesu ostavlja svoje vrednosti; zato se promenljiva pod merom uklanja ili postavlja izričito.
    protected function postaviOkruzenje(string $naziv, ?string $vrednost): void
    {
        $this->okruzenjePre[$naziv] ??= [getenv($naziv), $_ENV[$naziv] ?? null, $_SERVER[$naziv] ?? null];

        if ($vrednost === null) {
            putenv($naziv);
            unset($_ENV[$naziv], $_SERVER[$naziv]);

            return;
        }

        putenv($naziv.'='.$vrednost);
        $_ENV[$naziv] = $vrednost;
        $_SERVER[$naziv] = $vrednost;
    }

    protected function tearDown(): void
    {
        foreach ($this->okruzenjePre as $naziv => [$proces, $env, $server]) {
            $proces === false ? putenv($naziv) : putenv($naziv.'='.$proces);

            $env === null ? $this->ukloniIzNiza($_ENV, $naziv) : $_ENV[$naziv] = $env;
            $server === null ? $this->ukloniIzNiza($_SERVER, $naziv) : $_SERVER[$naziv] = $server;
        }

        $this->okruzenjePre = [];

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $niz */
    private function ukloniIzNiza(array &$niz, string $naziv): void
    {
        unset($niz[$naziv]);
    }
}
