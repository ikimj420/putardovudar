<?php

namespace App\Services;

use App\Enums\KoJeObjavio;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Ollama\OdgovorNijeJson;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\DatumUTekstu;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

// Ollama predlaže, kod odlučuje: objava samo ako je sve što model tvrdi proverljivo u tekstu strane.
final class ObradaNacrta
{
    private const UPUTSTVO = <<<'TEXT'
Proveravaš oglas za bazu prilika. Odgovaraj samo iz prosleđenog teksta, ništa ne dodaj iz sopstvenog znanja.
Pravilo: Objavljuje se stvarna prilika (posao, praksa, stipendija, konkurs ili obuka) dostupna ljudima iz Srbije, ili ono što je namenjeno Romima.
Rezultati konkursa, liste dobitnika, vesti i obaveštenja nisu prilika. Ako nije jasno, vazi_pravilo je false.
Vrati samo JSON objekat sa ključevima:
vazi_pravilo (true ili false),
razlog (jedna rečenica na srpskom latinicom, zašto pravilo važi ili ne važi),
vrsta (jedno od: posao, praksa, stipendija, konkurs, obuka, drugo),
rok (poslednji dan za prijavu, oblika YYYY-MM-DD, ili null ako u tekstu ne piše),
citat (tačan isečak iz teksta u kome piše rok, ili prazan tekst).
TEXT;

    public function __construct(private readonly Ollama $ollama, private readonly TekstStrane $strana) {}

    /** @throws OllamaNedostupna */
    public function obradi(Prilika $prilika): IshodObrade
    {
        try {
            $tekst = $this->strana->preuzmi((string) $prilika->link_izvora, $this->ollama->najviseZnakova());
        } catch (StranaNedostupna $izuzetak) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, $izuzetak->getMessage());
        }

        try {
            $forma = $this->ollama->odgovoriJsonom(self::UPUTSTVO, "Naslov: {$prilika->naslov}\n\nTekst strane:\n{$tekst}");
        } catch (OdgovorNijeJson) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, 'odgovor Ollame nije ispravan JSON');
        }

        if (! $this->imaOblik($forma)) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, 'odgovor Ollame nema tražena polja');
        }

        $odbijeno = $this->zasto($forma, $tekst);
        $predlog = [
            'vrsta' => $forma['vrsta'],
            'rok' => $forma['rok'],
            'razlog' => $forma['razlog'],
            'citat' => $forma['citat'],
            'odbijeno' => $odbijeno,
            'model' => $this->ollama->model(),
        ];

        $prilika->obradeno_at = now();
        $prilika->predlog = $predlog;

        if ($odbijeno === null) {
            $prilika->vrsta = VrstaPrilike::from($forma['vrsta']);
            $prilika->rok = $forma['rok'];
            $prilika->razlog_objave = $forma['razlog'];

            try {
                $prilika->objavi(KoJeObjavio::Ollama);

                return new IshodObrade(IshodObrade::OBJAVA, $forma['razlog']);
            } catch (ValidationException) {
                $prilika->refresh();
                $prilika->forceFill(['obradeno_at' => now(), 'predlog' => [...$predlog, 'odbijeno' => 'izvor']])->save();

                return new IshodObrade(IshodObrade::NACRT, 'nema ispravnog linka izvora');
            }
        }

        $prilika->save();

        return new IshodObrade(IshodObrade::NACRT, $odbijeno);
    }

    /** @param  array<string, mixed>  $forma */
    private function imaOblik(array $forma): bool
    {
        return is_bool($forma['vazi_pravilo'] ?? null)
            && is_string($forma['razlog'] ?? null)
            && $this->jeTekstIliNull($forma, 'vrsta')
            && $this->jeTekstIliNull($forma, 'rok')
            && $this->jeTekstIliNull($forma, 'citat');
    }

    // Kad pravilo ne važi, model vraća null za vrstu, rok i citat (izmereno u probi sa pravom Ollamom).
    /** @param  array<string, mixed>  $forma */
    private function jeTekstIliNull(array $forma, string $kljuc): bool
    {
        return array_key_exists($kljuc, $forma) && (is_string($forma[$kljuc]) || $forma[$kljuc] === null);
    }

    /**
     * Vraća razlog zbog kog nacrt ostaje nacrt, ili null kad je sve proverivo.
     *
     * @param  array<string, mixed>  $forma
     */
    private function zasto(array $forma, string $tekst): ?string
    {
        if ($forma['vazi_pravilo'] !== true) {
            return 'pravilo za objavu ne važi';
        }

        if (! is_string($forma['vrsta']) || VrstaPrilike::tryFrom($forma['vrsta']) === null) {
            return 'vrsta nije sa spiska';
        }

        $rok = is_string($forma['rok']) ? CarbonImmutable::createFromFormat('!Y-m-d', $forma['rok']) : null;

        if ($rok === null || $rok->format('Y-m-d') !== $forma['rok']) {
            return 'nema ispravnog roka';
        }

        $citat = TekstStrane::normalizuj((string) $forma['citat']);

        if (! str_contains(TekstStrane::normalizuj($tekst), $citat) || ! DatumUTekstu::postoji($citat, $forma['rok'])) {
            return 'rok ne piše u tekstu strane';
        }

        if ($rok->lessThan(today())) {
            return 'rok je prošao';
        }

        return null;
    }
}
