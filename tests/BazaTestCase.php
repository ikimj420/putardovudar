<?php

namespace Tests;

use PDO;
use PDOException;

// Server koji ne radi nije greška koda: testovi baze se preskaču, ali glasno i sa razlogom.
// Baza koja ne postoji ili odbija pristup ostaje greška, zato se ovde proverava samo server.
// Grupa se piše na svakoj potklasi: PHPUnit je ne nasleđuje, a bez nje `--group baza` ne vidi test.
abstract class BazaTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $veza = config('database.connections.'.config('database.default'));

        try {
            new PDO(
                'mysql:host='.$veza['host'].';port='.$veza['port'],
                $veza['username'],
                $veza['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2],
            );
        } catch (PDOException $izuzetak) {
            $this->markTestSkipped('MariaDB nije dostupna na '.$veza['host'].':'.$veza['port'].' ('.$izuzetak->getMessage().'), pa se testovi grupe baza preskaču.');
        }
    }
}
