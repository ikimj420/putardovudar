<?php

namespace App\Console\Commands;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\IshodObrade;
use App\Services\ObradaNacrta;
use App\Services\Ollama\OllamaNedostupna;
use Illuminate\Console\Command;

class UvozObradi extends Command
{
    protected $signature = 'uvoz:obradi';

    protected $description = 'Ollama predlaže, a kod proverava: nacrti iz uvoza koji ispune pravilo za objavu postaju objavljeni, ostali ostaju nacrti';

    public function handle(ObradaNacrta $obrada): int
    {
        // Nacrt iz uvoza je onaj koji uvoz ostavlja netaknut: vrsta „Drugo", bez roka, naziv izvora iz config/uvoz.php.
        $nacrti = Prilika::query()
            ->where('status', StatusPrilike::Nacrt)
            ->where('vrsta', VrstaPrilike::Drugo)
            ->whereNull('rok')
            ->whereNull('obradeno_at')
            ->whereIn('naziv_izvora', array_column(config('uvoz.izvori'), 'ime'))
            ->orderBy('id')
            ->get();

        if ($nacrti->isEmpty()) {
            $this->line('Nema nacrta iz uvoza za obradu.');

            return self::SUCCESS;
        }

        $objavljeno = 0;
        $nijeObradjeno = 0;
        $razlozi = [];

        foreach ($nacrti as $nacrt) {
            try {
                $ishod = $obrada->obradi($nacrt);
            } catch (OllamaNedostupna $izuzetak) {
                $this->line(sprintf('%s; ništa nije promenjeno posle ovog nacrta (obrađeno do sada: %d).', $izuzetak->getMessage(), $objavljeno + array_sum($razlozi)));

                return self::FAILURE;
            }

            match ($ishod->ishod) {
                IshodObrade::OBJAVA => $objavljeno++,
                IshodObrade::NACRT => $razlozi[$ishod->razlog] = ($razlozi[$ishod->razlog] ?? 0) + 1,
                default => $nijeObradjeno++,
            };

            $this->line(sprintf('%s (%s): %s', $ishod->ishod === IshodObrade::OBJAVA ? 'objavljen' : ($ishod->ishod === IshodObrade::NACRT ? 'ostaje nacrt' : 'nije obrađen'), $ishod->razlog, $nacrt->naslov));
        }

        $this->line(sprintf('Obrađeno nacrta: %d. Objavljeno: %d. Ostalo nacrt: %d. Nije obrađeno: %d.', $nacrti->count() - $nijeObradjeno, $objavljeno, array_sum($razlozi), $nijeObradjeno));

        foreach ($razlozi as $razlog => $broj) {
            $this->line(sprintf('  nacrt zbog „%s": %d', $razlog, $broj));
        }

        return self::SUCCESS;
    }
}
