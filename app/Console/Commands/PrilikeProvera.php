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
    // Prvo se sve proveri, pa se tek onda piše: ako bi u nacrt otišlo više od granice (uvoz.provera_najvise_u_nacrt), izvor je verovatno promenio izgled i ne piše se ništa (paket 96).
    public function handle(ProveraPrilike $provera): int
    {
        $prilike = Prilika::query()->javne()->orderBy('id')->get();
        $ishodi = [];

        foreach ($prilike as $prilika) {
            try {
                $ishodi[$prilika->getKey()] = $provera->proveri($prilika);
            } catch (Throwable $izuzetak) {
                // Jedna loša strana ne sme da zaustavi ostale: ostaje kako jeste, razlog se vidi u ispisu, a izuzetak ide u dnevnik.
                report($izuzetak);
                $ishodi[$prilika->getKey()] = $this->bezProvere($izuzetak);
            }
        }

        $bilo = count(array_filter($ishodi, fn (IshodProvere $ishod) => $ishod->ishod === IshodProvere::U_NACRT));
        $granica = (int) config('uvoz.provera_najvise_u_nacrt');
        $zaustavljeno = $bilo > $granica;
        $provereno = 0;
        $uNacrt = 0;
        $noviRok = 0;
        $bezProvere = 0;

        if ($zaustavljeno) {
            $this->line(sprintf('Upozorenje: u nacrt bi otišlo %d prilika, a granica je %d; izvor je verovatno promenio izgled strana. Ništa nije upisano.', $bilo, $granica));
        }

        foreach ($prilike as $prilika) {
            $ishod = $ishodi[$prilika->getKey()];

            if (! $zaustavljeno) {
                try {
                    $this->upisi($prilika, $ishod);
                } catch (Throwable $izuzetak) {
                    report($izuzetak);
                    $ishod = $this->bezProvere($izuzetak);
                }
            }

            match ($ishod->ishod) {
                IshodProvere::U_NACRT => $uNacrt += $zaustavljeno ? 0 : 1,
                IshodProvere::NOV_ROK => $noviRok += $zaustavljeno ? 0 : 1,
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
                $this->line(sprintf('%s%s: %s', $zaustavljeno ? 'Nije upisano: ' : '', $opis, $prilika->naslov));
            }
        }

        $this->line(sprintf('Provereno %d, vraćeno u nacrt %d, nov rok %d.', $provereno, $uNacrt, $noviRok));
        $this->line(sprintf('Nije provereno (strana nije stigla ili je prvi put nema): %d.', $bezProvere));

        return $zaustavljeno ? self::FAILURE : self::SUCCESS;
    }

    private function upisi(Prilika $prilika, IshodProvere $ishod): void
    {
        if ($ishod->stranaStigla) {
            $prilika->zaboraviNedostupnu();
        }

        match ($ishod->ishod) {
            IshodProvere::U_NACRT => $prilika->vratiUNacrt($ishod->razlog, $ishod->citat),
            IshodProvere::NOV_ROK => $prilika->promeniRok($ishod->rok ?? throw new \LogicException('nov rok bez datuma'), $ishod->citat),
            IshodProvere::NEDOSTUPNA => $prilika->zapamtiNedostupnu(),
            default => null,
        };
    }

    private function bezProvere(Throwable $izuzetak): IshodProvere
    {
        return new IshodProvere(IshodProvere::BEZ_PROVERE, 'greška pri proveri: '.mb_strimwidth((string) preg_replace('/\s+/u', ' ', $izuzetak->getMessage()), 0, 150, '…'));
    }
}
