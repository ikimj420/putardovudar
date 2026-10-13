<?php

namespace App\Services\Model;

use App\Services\Ollama\OdgovorNijeJson;
use App\Services\Ollama\OllamaNedostupna;

// Model koji obrađuje nacrte prilika: lokalna Ollama ili Claude API (MODEL_OBRADE). Isto uputstvo ide oba, pa je proba poštena.
interface ModelObrade
{
    public function model(): string;

    // Najviše znakova teksta koje šaljemo modelu.
    public function najviseZnakova(): int;

    /**
     * Pita model uz kratko uputstvo i vraća njegov odgovor kao JSON objekat.
     *
     * @return array<string, mixed>
     *
     * @throws OllamaNedostupna
     * @throws OdgovorNijeJson
     */
    public function odgovoriJsonom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): array;

    /** @throws OllamaNedostupna */
    public function odgovoriTekstom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): string;

    // Zbir svih poziva ovog objekta do sada.
    public function potrosnja(): Potrosnja;
}
