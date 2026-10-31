<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Session;

class SesijeOcisti extends Command
{
    protected $signature = 'sesije:ocisti';

    protected $description = 'Briše sesije starije od SESSION_LIFETIME, da adresa i pregledač ne stoje duže nego što piše na strani „Privatnost"';

    // Isto brisanje koje okvir radi nasumično, na deo zahteva (lottery u config/session.php), ali po rasporedu: ne zavisi od toga da li neko dolazi na sajt (paket 95, PITANJA-92 2).
    public function handle(): int
    {
        $obrisano = Session::driver('database')->getHandler()->gc((int) config('session.lifetime') * 60);

        $this->line(sprintf('Obrisano starih sesija: %d.', $obrisano));

        return self::SUCCESS;
    }
}
