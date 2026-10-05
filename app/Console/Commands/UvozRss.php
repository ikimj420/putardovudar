<?php

namespace App\Console\Commands;

use App\Services\UvozRss as Uvoz;
use Illuminate\Console\Command;

class UvozRss extends Command
{
    protected $signature = 'uvoz:rss';

    protected $description = 'Uvozi stavke sa RSS izvora iz config/uvoz.php kao nacrte prilika; ništa ne objavljuje';

    public function handle(Uvoz $uvoz): int
    {
        $izvori = config('uvoz.izvori');
        $neuspelih = 0;

        if ($izvori === []) {
            $this->line('Nema izvora u config/uvoz.php.');
        }

        foreach ($izvori as $izvor) {
            $ime = (string) ($izvor['ime'] ?? '');
            $rezultat = $uvoz->uvezi($ime, (string) ($izvor['adresa'] ?? ''));

            $this->line(sprintf('%s: novih %d, preskočeno %d, greška: %s', $ime, $rezultat->novih, $rezultat->preskoceno, $rezultat->greska ?? 'nema'));

            if ($rezultat->greska !== null) {
                $neuspelih++;
            }
        }

        return $neuspelih > 0 ? self::FAILURE : self::SUCCESS;
    }
}
