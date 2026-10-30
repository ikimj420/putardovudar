<?php

namespace App\Console\Commands;

use App\Services\UvozSpiska as Uvoz;
use Illuminate\Console\Command;

class UvozSpiskova extends Command
{
    protected $signature = 'uvoz:spiskovi';

    protected $description = 'Uvozi pozive sa HTML spiskova iz config/uvoz.php (izvori bez RSS-a) kao nacrte prilika; ništa ne objavljuje';

    public function handle(Uvoz $uvoz): int
    {
        $spiskovi = config('uvoz.spiskovi');
        $neuspelih = 0;

        if ($spiskovi === []) {
            $this->line('Nema spiskova u config/uvoz.php.');
        }

        foreach ($spiskovi as $spisak) {
            $ime = (string) ($spisak['ime'] ?? '');
            $rezultat = $uvoz->uvezi($ime, (string) ($spisak['adresa'] ?? ''), (string) ($spisak['izgled'] ?? 'nsz'));

            $this->line(sprintf('%s: novih %d, preskočeno %d, greška: %s%s', $ime, $rezultat->novih, $rezultat->preskoceno, $rezultat->greska ?? 'nema', $rezultat->dopunjeno > 0 ? ', dopunjen datum objave: '.$rezultat->dopunjeno : ''));

            if ($rezultat->greska !== null) {
                $neuspelih++;
            }
        }

        return $neuspelih > 0 ? self::FAILURE : self::SUCCESS;
    }
}
