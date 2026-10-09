<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UvozDnevno extends Command
{
    protected $signature = 'uvoz:dnevno';

    protected $description = 'Dnevni posao u rasporedu: uvoz:rss, uvoz:jooble pa uvoz:obradi; obrada ide i kad neki izvor ne radi';

    // Jedan posao umesto dva u rasporedu, da obrada ne krene pre kraja uvoza i da se dva posla ne preklapaju.
    public function handle(): int
    {
        $uspelo = true;

        foreach (['uvoz:rss', 'uvoz:jooble', 'uvoz:obradi'] as $komanda) {
            $this->line('→ '.$komanda);

            $uspelo = $this->call($komanda) === self::SUCCESS && $uspelo;
        }

        return $uspelo ? self::SUCCESS : self::FAILURE;
    }
}
