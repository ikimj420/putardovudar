<?php

namespace App\Services\Ollama;

use App\Services\Model\ModelObrade;
use App\Services\Model\Potrosnja;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Jedino mesto u aplikaciji koje zove Ollamu. Adresa, model i vreme čekanja dolaze iz config/ollama.php.
// Sa $samoLokalno (pomoćnik i „Napiši moj", vidi AppServiceProvider) poziv ide samo na petlju ili privatnu mrežu; bez njega (obrada uvoza) adresa je ona iz podešavanja.
final class Ollama implements ModelObrade
{
    public function __construct(private readonly ?LokalnaAdresa $samoLokalno = null) {}

    private int $poziva = 0;

    private int $ulazni = 0;

    private int $izlazni = 0;

    public function model(): string
    {
        return (string) config('ollama.model');
    }

    // Najviše znakova teksta koje šaljemo modelu; veći tekst ne staje u njegov kontekst.
    public function najviseZnakova(): int
    {
        return (int) config('ollama.najvise_znakova');
    }

    /**
     * Pita model uz kratko uputstvo i vraća njegov odgovor kao JSON objekat. Temperatura je 0.
     *
     * @return array<string, mixed>
     */
    public function odgovoriJsonom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): array
    {
        $sadrzaj = $this->pozovi($uputstvo, $tekst, true, $vremeCekanja);
        $niz = is_string($sadrzaj) ? json_decode($sadrzaj, true) : null;

        if (! is_array($niz) || array_is_list($niz) && $niz !== []) {
            throw new OdgovorNijeJson('Odgovor Ollame nije JSON objekat');
        }

        return $niz;
    }

    // Drugi posao klase: slobodan tekst. Prazan odgovor se vraća kao prazan tekst, a proveru radi pozivalac.
    public function odgovoriTekstom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): string
    {
        $sadrzaj = $this->pozovi($uputstvo, $tekst, false, $vremeCekanja);

        return is_string($sadrzaj) ? trim($sadrzaj) : '';
    }

    // Lokalno, bez cene; tokeni su oni koje Ollama sama javlja (prompt_eval_count i eval_count).
    public function potrosnja(): Potrosnja
    {
        return new Potrosnja($this->model(), $this->poziva, $this->ulazni, $this->izlazni);
    }

    // Pozivalac sme da skrati vreme čekanja (ostatak ukupnog vremena za pitanje), nikad da ga produži.
    private function pozovi(string $uputstvo, string $tekst, bool $kaoJson, ?int $vremeCekanja): mixed
    {
        // U dnevnik ide samo da je poziv odbijen, bez adrese i bez teksta pitanja: adresa može da nosi korisnika i lozinku, a pitanje je ono što strana obećava da ne čuvamo.
        if ($this->samoLokalno !== null && ! $this->samoLokalno->jeLokalna((string) config('ollama.adresa'))) {
            Log::warning('Ollama: adresa nije lokalna, poziv je odbijen.');

            throw new OllamaNedostupna('Ollama: adresa nije lokalna');
        }

        $granica = (int) config('ollama.vreme_cekanja');

        try {
            $odgovor = Http::timeout($vremeCekanja === null ? $granica : min($granica, max(1, $vremeCekanja)))
                ->acceptJson()
                ->post(rtrim((string) config('ollama.adresa'), '/').'/api/chat', [
                    'model' => config('ollama.model'),
                    'stream' => false,
                    ...($kaoJson ? ['format' => 'json'] : []),
                    'options' => ['temperature' => 0, 'num_ctx' => config('ollama.kontekst')],
                    'messages' => [
                        ['role' => 'system', 'content' => $uputstvo],
                        ['role' => 'user', 'content' => $tekst],
                    ],
                ]);
        } catch (ConnectionException $izuzetak) {
            throw new OllamaNedostupna('Ollama ne odgovara na '.config('ollama.adresa'), previous: $izuzetak);
        }

        if (! $odgovor->successful()) {
            throw new OllamaNedostupna('Ollama je odgovorila sa HTTP '.$odgovor->status());
        }

        $this->poziva++;
        $this->ulazni += (int) $odgovor->json('prompt_eval_count');
        $this->izlazni += (int) $odgovor->json('eval_count');

        return $odgovor->json('message.content');
    }
}
