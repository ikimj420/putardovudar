<?php

namespace App\Console\Commands;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\IshodObrade;
use App\Services\Model\ModelNedostupan;
use App\Services\Model\ModelObrade;
use App\Services\Model\NepoznatModelObrade;
use App\Services\ObradaNacrta;
use App\Support\Prikaz;
use Illuminate\Console\Command;
use Throwable;

class UvozObradi extends Command
{
    protected $signature = 'uvoz:obradi {--ponovo : ponovo obradi nacrte iz uvoza obrađene po starom pravilu}';

    protected $description = 'Model (Claude) predlaže, a kod proverava: nacrti iz uvoza koji ispune pravilo za objavu postaju objavljeni, ostali ostaju nacrti';

    public function handle(): int
    {
        // Model se traži ovde, ne u potpisu: pogrešan MODEL_OBRADE tako postaje poruka i izlaz 1, a dnevni posao nastavlja sa ostalim koracima (paket 95).
        try {
            $obrada = app(ObradaNacrta::class);
            $model = app(ModelObrade::class);
        } catch (NepoznatModelObrade $izuzetak) {
            $this->line($izuzetak->getMessage());

            return self::FAILURE;
        }

        // Nacrt iz uvoza je onaj koji uvoz ostavlja netaknut: vrsta „Drugo", bez roka, naziv izvora iz config/uvoz.php ili Jooble.
        $upit = Prilika::query()
            ->where('status', StatusPrilike::Nacrt)
            ->where('vrsta', VrstaPrilike::Drugo)
            ->whereNull('rok')
            ->whereIn('naziv_izvora', [...array_column(config('uvoz.izvori'), 'ime'), ...array_column(config('uvoz.spiskovi'), 'ime'), config('jooble.ime')])
            ->orderBy('id');

        // Običan prolaz uzima neobrađene; --ponovo uzima obrađene po starom pravilu (u predlogu nema verzije pravila ili je manja od tekuće), ali samo
        // dok ih niko nije menjao: Ivanova izmena posle obrade pomera vreme izmene, a obrada ga postavlja u istom trenutku.
        $nacrti = $this->option('ponovo')
            ? $upit->whereNotNull('obradeno_at')->where(fn ($staro) => $staro->whereNull('predlog->pravilo')->orWhere('predlog->pravilo', '<', ObradaNacrta::PRAVILO))->get()
                ->filter(fn (Prilika $nacrt) => $nacrt->nijeMenjanRucno())
                ->values()
            : $upit->whereNull('obradeno_at')->get();

        if ($nacrti->isEmpty()) {
            $this->line('Nema nacrta iz uvoza za obradu.');

            return self::SUCCESS;
        }

        $objavljeno = 0;
        $nijeObradjeno = 0;
        $obradjeno = 0;
        $razlozi = [];
        $najvisePoziva = max(1, (int) config('claude.najvise_poziva_po_prolazu'));
        $pozivaNaPocetku = $model->potrosnja()->poziva;

        foreach ($nacrti as $nacrt) {
            if ($model->potrosnja()->poziva - $pozivaNaPocetku >= $najvisePoziva) {
                $this->line(sprintf('Granica od %d poziva modela po prolazu je dostignuta; obrada staje. Čeka sledeći prolaz: %d.', $najvisePoziva, $nacrti->count() - $obradjeno));

                break;
            }

            try {
                $ishod = $obrada->obradi($nacrt);
            } catch (ModelNedostupan $izuzetak) {
                $this->line(sprintf('%s; ništa nije promenjeno posle ovog nacrta (obrađeno do sada: %d).', $izuzetak->getMessage(), $objavljeno + array_sum($razlozi)));
                $this->potrosnja($model);

                return self::FAILURE;
            } catch (Throwable $izuzetak) {
                // Jedna loša stavka ne sme da zaustavi ostale (paket 74): ostaje neobrađena, razlog se vidi u ispisu, a izuzetak ide u dnevnik.
                report($izuzetak);
                $ishod = new IshodObrade(IshodObrade::NIJE_OBRADJENO, 'greška pri obradi: '.mb_strimwidth((string) preg_replace('/\s+/u', ' ', $izuzetak->getMessage()), 0, 150, '…'));
            }

            match ($ishod->ishod) {
                IshodObrade::OBJAVA => $objavljeno++,
                IshodObrade::NACRT => $razlozi[$ishod->razlog] = ($razlozi[$ishod->razlog] ?? 0) + 1,
                default => $nijeObradjeno++,
            };

            $this->line(sprintf('%s (%s): %s', $ishod->ishod === IshodObrade::OBJAVA ? 'objavljen' : ($ishod->ishod === IshodObrade::NACRT ? 'ostaje nacrt' : 'nije obrađen'), $ishod->razlog, $nacrt->naslov));
            $obradjeno++;
        }

        $this->line(sprintf('Obrađeno nacrta: %d. Objavljeno: %d. Ostalo nacrt: %d. Nije obrađeno: %d.', $obradjeno - $nijeObradjeno, $objavljeno, array_sum($razlozi), $nijeObradjeno));

        foreach ($razlozi as $razlog => $broj) {
            $this->line(sprintf('  nacrt zbog „%s": %d', $razlog, $broj));
        }

        $this->potrosnja($model);

        return self::SUCCESS;
    }

    // Koji je model radio, koliko poziva, tokena i (za Claude) koliko je to otprilike koštalo; cene su u config/claude.php.
    private function potrosnja(ModelObrade $model): void
    {
        $p = $model->potrosnja();

        $this->line(sprintf(
            'Model: %s. Poziva: %d. Tokeni: ulaz %d, izlaz %d. %s',
            $p->model,
            $p->poziva,
            $p->ulazniTokeni,
            $p->izlazniTokeni,
            $p->cena === null ? 'Procena cene: nema (lokalni model ili nepoznata cena).' : 'Procena cene: $'.Prikaz::broj($p->cena, 4).'.',
        ));
    }
}
