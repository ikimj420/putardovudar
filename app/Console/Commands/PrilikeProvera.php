<?php

namespace App\Console\Commands;

use App\Models\Prilika;
use App\Services\IshodProvere;
use App\Services\ProveraPrilike;
use App\Support\Prikaz;
use Illuminate\Console\Command;
use Throwable;

class PrilikeProvera extends Command
{
    protected $signature = 'prilike:provera';

    protected $description = 'Proverava svaku javnu priliku prema strani izvora: vraća u nacrt zatvorenu ili bez roka na strani, upisuje nov rok koji strana sama piše; model se ne zove';

    // Koje prilike su javne kaže Prilika::javne; šta se menja i uz koji dokaz kaže ProveraPrilike. Rok i status piše samo model prilike (vratiUNacrt, promeniRok).
    public function handle(ProveraPrilike $provera): int
    {
        $provereno = 0;
        $uNacrt = 0;
        $noviRok = 0;
        $bezProvere = 0;

        foreach (Prilika::query()->javne()->orderBy('id')->get() as $prilika) {
            try {
                $ishod = $provera->proveri($prilika);

                if ($ishod->stranaStigla) {
                    $prilika->zaboraviNedostupnu();
                }

                match ($ishod->ishod) {
                    IshodProvere::U_NACRT => $prilika->vratiUNacrt($ishod->razlog, $ishod->citat),
                    IshodProvere::NOV_ROK => $prilika->promeniRok($ishod->rok ?? throw new \LogicException('nov rok bez datuma'), $ishod->citat),
                    IshodProvere::NEDOSTUPNA => $prilika->zapamtiNedostupnu(),
                    default => null,
                };
            } catch (Throwable $izuzetak) {
                // Jedna loša strana ne sme da zaustavi ostale: ostaje kako jeste, razlog se vidi u ispisu, a izuzetak ide u dnevnik.
                report($izuzetak);
                $ishod = new IshodProvere(IshodProvere::BEZ_PROVERE, 'greška pri proveri: '.mb_strimwidth((string) preg_replace('/\s+/u', ' ', $izuzetak->getMessage()), 0, 150, '…'));
            }

            match ($ishod->ishod) {
                IshodProvere::U_NACRT => $uNacrt++,
                IshodProvere::NOV_ROK => $noviRok++,
                IshodProvere::NEDOSTUPNA, IshodProvere::BEZ_PROVERE => $bezProvere++,
                default => null,
            };

            if (! in_array($ishod->ishod, [IshodProvere::NEDOSTUPNA, IshodProvere::BEZ_PROVERE], true)) {
                $provereno++;
            }

            $opis = match ($ishod->ishod) {
                IshodProvere::U_NACRT => sprintf('vraćeno u nacrt (%s)', $ishod->razlog),
                IshodProvere::NOV_ROK => sprintf('nov rok %s', Prikaz::datum($ishod->rok ?? throw new \LogicException('nov rok bez datuma'))),
                IshodProvere::NEDOSTUPNA => sprintf('strane nema prvi put (%s)', $ishod->razlog),
                IshodProvere::BEZ_PROVERE => sprintf('nije provereno (%s)', $ishod->razlog),
                default => null,
            };

            if ($opis !== null) {
                $this->line(sprintf('%s: %s', $opis, $prilika->naslov));
            }
        }

        $this->line(sprintf('Provereno %d, vraćeno u nacrt %d, nov rok %d.', $provereno, $uNacrt, $noviRok));
        $this->line(sprintf('Nije provereno (strana nije stigla ili je prvi put nema): %d.', $bezProvere));

        return self::SUCCESS;
    }
}
