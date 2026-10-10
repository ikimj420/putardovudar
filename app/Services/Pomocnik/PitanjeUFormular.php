<?php

namespace App\Services\Pomocnik;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Ollama\OdgovorNijeJson;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\PoredjenjeTeksta;

// Prvi posao pomoćnika: model samo popunjava formular, a svaku vrednost iz njega proverava kod.
final class PitanjeUFormular
{
    private const UPUTSTVO = <<<'TEXT'
Pretvaraš pitanje o prilikama (posao, praksa, stipendija, konkurs, obuka) u formular za pretragu. Ne odgovaraš na pitanje.
Popuni formular samo iz teksta pitanja; ako nešto u pitanju ne piše, stavi null.
Vrati samo JSON objekat sa ključevima:
vrsta (jedno od: posao, praksa, stipendija, konkurs, obuka, drugo, ili null),
grad (ime grada u Srbiji iz pitanja, u nominativu, ili null; Romi i Rome nisu grad),
kome (kome je namenjeno: Romi, žene, mladi, studenti i slično, ili null),
kljucne_reci (sve ostale imenice iz pitanja koje bliže određuju šta se traži, na primer zanimanje ili oblast, bez vrste i grada; prazna lista ako ih nema).
Primer: pitanje "Ima li posla za vozače u Nišu?" daje {"vrsta":"posao","grad":"Niš","kome":null,"kljucne_reci":["vozače"]}.
TEXT;

    private const NAJVISE_RECI = 5;

    private const NAJVISE_ZNAKOVA_VREDNOSTI = 40;

    public function __construct(private readonly Ollama $ollama) {}

    /** @throws OllamaNedostupna */
    public function izPitanja(string $pitanje, ?int $vremeCekanja = null): Formular
    {
        try {
            $forma = $this->ollama->odgovoriJsonom(self::UPUTSTVO, $pitanje, $vremeCekanja);
        } catch (OdgovorNijeJson) {
            return new Formular;
        }

        $pitanjeNormalizovano = PoredjenjeTeksta::normalizuj($pitanje);

        $vrsta = is_string($forma['vrsta'] ?? null) ? VrstaPrilike::tryFrom(mb_strtolower(trim($forma['vrsta']))) : null;
        $grad = $this->grad($forma['grad'] ?? null, $pitanjeNormalizovano);
        $kome = $this->rec($forma['kome'] ?? null, $pitanjeNormalizovano);
        $reci = $this->kljucneReci($forma['kljucne_reci'] ?? null, $pitanjeNormalizovano, [$vrsta?->value, $vrsta?->getLabel(), $grad, $kome]);
        $sveReci = $this->kljucneReci($forma['kljucne_reci'] ?? null, $pitanjeNormalizovano, [$grad]);

        return new Formular($vrsta, $grad, $kome, $reci, $sveReci, $this->vrstaJePomenuta($vrsta, $pitanjeNormalizovano));
    }

    // Vrsta je izrečena samo kad pitanje kaže njen naziv (u bilo kom padežu). Inače je model nagađa („radionica" je „obuka"),
    // pa se ne sme tvrdo primenjivati. „Drugo" je ostatak i nikad nije izrečeno.
    private function vrstaJePomenuta(?VrstaPrilike $vrsta, string $pitanjeNormalizovano): bool
    {
        return $vrsta !== null && $vrsta !== VrstaPrilike::Drugo && PoredjenjeTeksta::sadrziIzraz($pitanjeNormalizovano, PoredjenjeTeksta::normalizuj($vrsta->getLabel()));
    }

    // Grad mora da se pominje u pitanju (u bilo kom padežu). Ako postoji u objavljenim prilikama, uzima se kako piše u bazi;
    // ako ga u bazi nema, ostaje kako je napisan, pa pretraga ne vraća ništa — pitanje o drugom gradu nije pitanje o ovom.
    private function grad(mixed $grad, string $pitanjeNormalizovano): ?string
    {
        if (! is_string($grad) || mb_strlen($grad) > self::NAJVISE_ZNAKOVA_VREDNOSTI) {
            return null;
        }

        $trazen = PoredjenjeTeksta::normalizuj($grad);

        if ($trazen === '' || ! PoredjenjeTeksta::sadrziIzraz($pitanjeNormalizovano, $trazen)) {
            return null;
        }

        foreach (Prilika::javne()->whereNotNull('mesto')->distinct()->pluck('mesto') as $mesto) {
            if ($this->istiGrad(PoredjenjeTeksta::normalizuj((string) $mesto), $trazen)) {
                return (string) $mesto;
            }
        }

        return trim($grad);
    }

    // Model grad ponekad napiše u padežu („Čačk", „Kragujevc"), pa se reči porede kao ista reč u drugom padežu.
    private function istiGrad(string $izBaze, string $izModela): bool
    {
        $baza = explode(' ', $izBaze);
        $model = explode(' ', $izModela);

        if (count($baza) !== count($model)) {
            return false;
        }

        foreach ($baza as $i => $rec) {
            if (! PoredjenjeTeksta::istaRec($rec, $model[$i])) {
                return false;
            }
        }

        return true;
    }

    // Reč se priznaje samo ako piše u pitanju, u bilo kom padežu.
    private function rec(mixed $vrednost, string $pitanjeNormalizovano): ?string
    {
        if (! is_string($vrednost) || mb_strlen($vrednost) > self::NAJVISE_ZNAKOVA_VREDNOSTI) {
            return null;
        }

        $normalizovano = PoredjenjeTeksta::normalizuj($vrednost);

        if ($normalizovano === '' || ! PoredjenjeTeksta::sadrziIzraz($pitanjeNormalizovano, $normalizovano)) {
            return null;
        }

        return trim($vrednost);
    }

    /**
     * @param  list<string|null>  $vecIskazano
     * @return list<string>
     */
    private function kljucneReci(mixed $reci, string $pitanjeNormalizovano, array $vecIskazano): array
    {
        if (! is_array($reci)) {
            return [];
        }

        $iskazano = array_map(fn (?string $vrednost) => PoredjenjeTeksta::normalizuj((string) $vrednost), $vecIskazano);
        $prihvaceno = [];

        foreach ($reci as $rec) {
            $prihvacena = $this->rec($rec, $pitanjeNormalizovano);

            // Reč koja samo ponavlja vrstu, grad ili „kome" ne sužava pretragu, nego je kvari (padež: „posla" prema „posao").
            if ($prihvacena === null || $this->ponavlja($prihvacena, [...$iskazano, ...array_map(PoredjenjeTeksta::normalizuj(...), $prihvaceno)])) {
                continue;
            }

            $prihvaceno[] = $prihvacena;

            if (count($prihvaceno) === self::NAJVISE_RECI) {
                break;
            }
        }

        return $prihvaceno;
    }

    /** @param  list<string>  $vecIskazano */
    private function ponavlja(string $rec, array $vecIskazano): bool
    {
        $reciIskazanog = array_filter(explode(' ', implode(' ', $vecIskazano)), fn (string $deo) => $deo !== '');

        foreach (explode(' ', PoredjenjeTeksta::normalizuj($rec)) as $deo) {
            $ponovljena = false;

            foreach ($reciIskazanog as $iskazana) {
                $ponovljena = $ponovljena || PoredjenjeTeksta::istaRec($deo, $iskazana);
            }

            if (! $ponovljena) {
                return false;
            }
        }

        return true;
    }
}
