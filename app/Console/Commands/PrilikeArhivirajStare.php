<?php

namespace App\Console\Commands;

use App\Models\Prilika;
use Illuminate\Console\Command;

class PrilikeArhivirajStare extends Command
{
    protected $signature = 'prilike:arhiviraj-stare';

    protected $description = 'Arhivira nacrte kojima je rok prošao pre više od 30 dana; objavljene ne dira';

    // Koji nacrti su stari kaže Prilika::zaArhiviranje, a arhivira samo Prilika::arhiviraj (paket 86, Ivan 30.10.2026.). Bez brisanja; arhivirano se ne vraća u obradu ni na sajt.
    public function handle(): int
    {
        $arhivirano = 0;

        foreach (Prilika::query()->zaArhiviranje()->lazyById() as $nacrt) {
            $arhivirano += $nacrt->arhiviraj() ? 1 : 0;
        }

        $this->line(sprintf('Arhivirano starih nacrta: %d.', $arhivirano));

        return self::SUCCESS;
    }
}
