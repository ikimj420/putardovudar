<?php

namespace App\Console\Commands;

use App\Services\UvozJooble as Uvoz;
use Illuminate\Console\Command;

class UvozJooble extends Command
{
    protected $signature = 'uvoz:jooble';

    protected $description = 'Uvozi najviše 50 poslova iz Srbije sa Jooble-a kao nacrte; bez ključa (JOOBLE_KLJUC) se preskače';

    public function handle(Uvoz $uvoz): int
    {
        $ime = (string) config('jooble.ime');

        if (trim((string) config('jooble.kljuc')) === '') {
            $this->line($ime.': preskočeno, JOOBLE_KLJUC nije podešen.');

            return self::SUCCESS;
        }

        $rezultat = $uvoz->uvezi();

        $this->line(sprintf('%s: novih %d, preskočeno %d, greška: %s', $ime, $rezultat->novih, $rezultat->preskoceno, $rezultat->greska ?? 'nema'));

        return $rezultat->greska === null ? self::SUCCESS : self::FAILURE;
    }
}
