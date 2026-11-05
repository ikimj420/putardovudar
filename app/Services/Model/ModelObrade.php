<?php

namespace App\Services\Model;

// Model koji obrađuje nacrte prilika i piše za pomoćnika: Claude API (paket 100; Ollama je izbačena).
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
     * @throws ModelNedostupan
     * @throws OdgovorNijeJson
     */
    public function odgovoriJsonom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): array;

    /** @throws ModelNedostupan */
    public function odgovoriTekstom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): string;

    // Zbir svih poziva ovog objekta do sada.
    public function potrosnja(): Potrosnja;
}
