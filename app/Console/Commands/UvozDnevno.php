<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UvozDnevno extends Command
{
    protected $signature = 'uvoz:dnevno';

    protected $description = 'Dnevni posao u rasporedu: uvoz:rss, uvoz:spiskovi, uvoz:jooble, uvoz:obradi, prilike:provera pa kalendar:iz-prilika; obrada ide i kad neki izvor ne radi';

    // Jedan posao umesto dva u rasporedu, da obrada ne krene pre kraja uvoza i da se dva posla ne preklapaju. Provera javnih prilika ide posle obrade (paket 85), a pre kalendara,
    // koji uzima samo objavljene.
    public function handle(): int
    {
        $uspelo = true;

        foreach (['uvoz:rss', 'uvoz:spiskovi', 'uvoz:jooble', 'uvoz:obradi', 'prilike:provera', 'kalendar:iz-prilika'] as $komanda) {
            $this->line('→ '.$komanda);

            $uspelo = $this->call($komanda) === self::SUCCESS && $uspelo;
        }

        return $uspelo ? self::SUCCESS : self::FAILURE;
    }
}
