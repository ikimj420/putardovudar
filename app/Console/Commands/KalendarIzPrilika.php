<?php

namespace App\Console\Commands;

use App\Models\Prilika;
use App\Services\KalendarIzPrilike;
use Illuminate\Console\Command;

class KalendarIzPrilika extends Command
{
    protected $signature = 'kalendar:iz-prilika';

    protected $description = 'Za objavljene prilike kojima je rok prošao (osim poslova) pravi nacrte stavki kalendara; ništa ne objavljuje';

    // Koje prilike idu u kalendar kaže Prilika::zaKalendar. Isti rok iste prilike ulazi jednom (KalendarIzPrilike), pa drugi prolaz ništa ne dodaje. Objavljuje Ivan, u adminu.
    public function handle(KalendarIzPrilike $kalendar): int
    {
        $novih = 0;

        $prilike = Prilika::query()->zaKalendar()->orderBy('id')->get();

        foreach ($prilike as $prilika) {
            $novih += $kalendar->dodaj($prilika)->wasRecentlyCreated ? 1 : 0;
        }

        $this->line(sprintf('Novih nacrta u kalendaru: %d.', $novih));

        return self::SUCCESS;
    }
}
