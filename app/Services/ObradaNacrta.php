<?php

namespace App\Services;

use App\Enums\Grupa;
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
Pravilo: Objavljuje se stvarna prilika (posao, praksa, stipendija, konkurs ili obuka) dostupna u Srbiji bar jednoj od ovih grupa: osnovci, srednjoškolci, studenti, master i doktorski studenti, nezaposleni mladi, povratnici iz inostranstva, organizacije — ili ono što je namenjeno Romima.
Organizacije su udruženja i organizacije civilnog društva, ne firme; ono što je samo za firme nije za nijednu grupu.
Rezultati konkursa, liste dobitnika, vesti i obaveštenja nisu prilika. Ako nije jasno, vazi_pravilo je false.
Vrati samo JSON objekat sa ključevima, tačno ovim redom:
grupe (lista objekata {"grupa": ..., "citat": ...}, po jedan za svaku grupu kojoj je prilika namenjena, ili prazna lista; grupa je jedno od: osnovci, srednjoskolci, studenti, master-doktorski (master ili doktorske studije), nezaposleni-mladi, povratnici (iz inostranstva), organizacije (udruženja i organizacije civilnog društva), romi; citat je tačan isečak iz teksta koji kaže da je prilika namenjena toj grupi i sadrži reč te grupe),
vazi_pravilo (true samo ako je bar jedna grupa navedena sa citatom i tekst je prilika, a ne rezultat, lista ili vest; inače false),
razlog (jedna rečenica na srpskom latinicom, zašto pravilo važi ili ne važi),
vrsta (jedno od: posao, praksa, stipendija, konkurs, obuka, drugo; reč te vrste ili njoj srodna mora da piše u tekstu),
rok (poslednji dan za prijavu, oblika YYYY-MM-DD, ili null ako u tekstu ne piše),
citat (tačan isečak iz teksta u kome piše rok, ili prazan tekst).
TEXT;

    // Verzija pravila upisana u predlog; nacrt obrađen starim pravilom je nema, pa ga uvoz:obradi --ponovo prepoznaje.
    public const PRAVILO = 2;

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

        $grupe = $this->grupeModela($forma);
        $prihvacene = $this->prihvaceneGrupe($grupe, TekstStrane::normalizuj($tekst));
        $odbijeno = $this->zasto($forma, $tekst, $prihvacene);
        $predlog = [
            'pravilo' => self::PRAVILO,
            'vrsta' => $forma['vrsta'],
            'rok' => $forma['rok'],
            'razlog' => $forma['razlog'],
            'citat' => $forma['citat'],
            'grupe' => $grupe,
            'prihvacene_grupe' => array_map(fn (Grupa $grupa) => $grupa->value, $prihvacene),
            'odbijeno' => $odbijeno,
            'model' => $this->ollama->model(),
        ];

        $prilika->obradeno_at = now();
        $prilika->predlog = $predlog;

        if ($odbijeno === null) {
            $prilika->vrsta = VrstaPrilike::from($forma['vrsta']);
            $prilika->grupe = array_map(fn (Grupa $grupa) => $grupa->value, $prihvacene);
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

    // Model vraća grupe sa citatom za svaku; ostaje samo šta ima oblik {grupa, citat} i grupu sa spiska.
    /**
     * @param  array<string, mixed>  $forma
     * @return list<array{grupa: string, citat: string}>
     */
    private function grupeModela(array $forma): array
    {
        $grupe = [];

        foreach (is_array($forma['grupe'] ?? null) ? $forma['grupe'] : [] as $stavka) {
            $grupa = is_array($stavka) && is_string($stavka['grupa'] ?? null) ? Grupa::tryFrom($stavka['grupa']) : null;

            if ($grupa !== null && is_string($stavka['citat'] ?? null)) {
                $grupe[] = ['grupa' => $grupa->value, 'citat' => $stavka['citat']];
            }
        }

        return $grupe;
    }

    // Grupa se prihvata samo kad njen citat doslovno piše na strani i sadrži reč te grupe.
    /**
     * @param  list<array{grupa: string, citat: string}>  $grupe
     * @return list<Grupa>
     */
    private function prihvaceneGrupe(array $grupe, string $strana): array
    {
        $prihvacene = [];

        foreach ($grupe as $stavka) {
            $grupa = Grupa::from($stavka['grupa']);
            $citat = TekstStrane::normalizuj($stavka['citat']);

            if ($citat !== '' && str_contains($strana, $citat) && $grupa->potkrepljuje($citat)) {
                $prihvacene[] = $grupa;
            }
        }

        return Grupa::izVrednosti(array_map(fn (Grupa $grupa) => $grupa->value, $prihvacene));
    }

    /**
     * Vraća razlog zbog kog nacrt ostaje nacrt, ili null kad je sve proverivo.
     *
     * @param  array<string, mixed>  $forma
     * @param  list<Grupa>  $prihvacene
     */
    private function zasto(array $forma, string $tekst, array $prihvacene): ?string
    {
        if ($forma['vazi_pravilo'] !== true) {
            return 'pravilo za objavu ne važi';
        }

        $strana = TekstStrane::normalizuj($tekst);

        if ($prihvacene === []) {
            return 'pravilo nije potkrepljeno citatom';
        }

        $vrsta = is_string($forma['vrsta']) ? VrstaPrilike::tryFrom($forma['vrsta']) : null;

        if ($vrsta === null) {
            return 'vrsta nije sa spiska';
        }

        // Vrsta se dokazuje rečju sa strane (ne samo citatom): „stipendija" bez ijedne reči o stipendiji ostaje nacrt.
        if (! $vrsta->potkrepljenaTekstom($tekst)) {
            return 'vrsta nije potkrepljena';
        }

        $rok = is_string($forma['rok']) ? CarbonImmutable::createFromFormat('!Y-m-d', $forma['rok']) : null;

        if ($rok === null || $rok->format('Y-m-d') !== $forma['rok']) {
            return 'nema ispravnog roka';
        }

        $citat = TekstStrane::normalizuj((string) $forma['citat']);

        if (! str_contains($strana, $citat) || ! DatumUTekstu::postoji($citat, $forma['rok'])) {
            return 'rok ne piše u tekstu strane';
        }

        if ($rok->lessThan(today())) {
            return 'rok je prošao';
        }

        return null;
    }
}
