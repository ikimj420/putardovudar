<?php

namespace App\Services\Model;

// Model koji obrađuje nacrte prilika: Claude API (paket 100; Ollama je izbačena). Jedina klasa je Claude, koja je final; interfejs ostaje jer kroz njega
// UvozObradiNevazeciUtf8Test podmeće model koji vraća nevažeći UTF-8 ili baca izuzetak usred obrade (paket 101/T3).
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
