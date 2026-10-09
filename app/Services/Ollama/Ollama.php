<?php

namespace App\Services\Ollama;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// Jedino mesto u aplikaciji koje zove Ollamu. Adresa, model i vreme čekanja dolaze iz config/ollama.php.
final class Ollama
{
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
    public function odgovoriJsonom(string $uputstvo, string $tekst): array
    {
        $sadrzaj = $this->pozovi($uputstvo, $tekst, true);
        $niz = is_string($sadrzaj) ? json_decode($sadrzaj, true) : null;

        if (! is_array($niz) || array_is_list($niz) && $niz !== []) {
            throw new OdgovorNijeJson('Odgovor Ollame nije JSON objekat');
        }

        return $niz;
    }

    // Drugi posao klase: slobodan tekst. Prazan odgovor se vraća kao prazan tekst, a proveru radi pozivalac.
    public function odgovoriTekstom(string $uputstvo, string $tekst): string
    {
        $sadrzaj = $this->pozovi($uputstvo, $tekst, false);

        return is_string($sadrzaj) ? trim($sadrzaj) : '';
    }

    private function pozovi(string $uputstvo, string $tekst, bool $kaoJson): mixed
    {
        try {
            $odgovor = Http::timeout(config('ollama.vreme_cekanja'))
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

        return $odgovor->json('message.content');
    }
}
