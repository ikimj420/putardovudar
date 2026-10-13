<?php

namespace App\Services\Claude;

use App\Services\Model\ModelObrade;
use App\Services\Model\Potrosnja;
use App\Services\Ollama\OdgovorNijeJson;
use App\Services\Ollama\OllamaNedostupna;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// Jedino mesto u aplikaciji koje zove Claude API (Messages). Ključ, model i cene dolaze iz config/claude.php; ključ se nikad ne piše u poruku greške ni u log.
// Greške su iste kao kod Ollame (OllamaNedostupna, OdgovorNijeJson), pa uvoz:obradi ne zna koji model radi.
final class Claude implements ModelObrade
{
    private int $poziva = 0;

    private int $ulazni = 0;

    private int $izlazni = 0;

    public function model(): string
    {
        return (string) config('claude.model');
    }

    public function najviseZnakova(): int
    {
        return (int) config('claude.najvise_znakova');
    }

    public function odgovoriJsonom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): array
    {
        $niz = $this->objekat($this->pozovi($uputstvo, $tekst, $vremeCekanja));

        if ($niz === null) {
            throw new OdgovorNijeJson('Odgovor Claude-a nije JSON objekat');
        }

        return $niz;
    }

    public function odgovoriTekstom(string $uputstvo, string $tekst, ?int $vremeCekanja = null): string
    {
        return trim($this->pozovi($uputstvo, $tekst, $vremeCekanja));
    }

    public function potrosnja(): Potrosnja
    {
        $cene = config('claude.cene')[$this->model()] ?? null;

        return new Potrosnja(
            $this->model(),
            $this->poziva,
            $this->ulazni,
            $this->izlazni,
            is_array($cene) ? $this->ulazni / 1_000_000 * $cene['ulaz'] + $this->izlazni / 1_000_000 * $cene['izlaz'] : null,
        );
    }

    // Model ume da obavije JSON u ogradu ili da napiše reč pre njega; uzima se prvi objekat. Prazan objekat važi, kao kod Ollame; niz ne.
    /** @return array<string, mixed>|null */
    private function objekat(string $sadrzaj): ?array
    {
        $niz = json_decode(trim($sadrzaj), true);

        if (! is_array($niz)) {
            $od = strpos($sadrzaj, '{');
            $do = strrpos($sadrzaj, '}');
            $niz = $od !== false && $do !== false && $do > $od ? json_decode(substr($sadrzaj, $od, $do - $od + 1), true) : null;
        }

        return is_array($niz) && ! (array_is_list($niz) && $niz !== []) ? $niz : null;
    }

    private function pozovi(string $uputstvo, string $tekst, ?int $vremeCekanja): string
    {
        $kljuc = config('claude.kljuc');

        if (! is_string($kljuc) || trim($kljuc) === '') {
            throw new OllamaNedostupna('Claude API: ključ nije podešen (ANTHROPIC_API_KEY)');
        }

        $granica = (int) config('claude.vreme_cekanja');
        $cekanje = array_values((array) config('claude.ponavljanja_cekanje'));

        for ($pokusaj = 0; ; $pokusaj++) {
            try {
                $odgovor = Http::timeout($vremeCekanja === null ? $granica : min($granica, max(1, $vremeCekanja)))
                    ->withHeaders(['x-api-key' => $kljuc, 'anthropic-version' => (string) config('claude.verzija_api')])
                    ->acceptJson()
                    ->post(rtrim((string) config('claude.adresa'), '/').'/v1/messages', [
                        'model' => $this->model(),
                        'max_tokens' => (int) config('claude.najvise_izlaznih_tokena'),
                        ...(config('claude.temperatura') !== null ? ['temperature' => (float) config('claude.temperatura')] : []),
                        'system' => $uputstvo,
                        'messages' => [['role' => 'user', 'content' => $tekst]],
                    ]);
            } catch (ConnectionException) {
                throw new OllamaNedostupna('Claude API ne odgovara');
            }

            if (in_array($odgovor->status(), [429, 529], true) && $pokusaj < count($cekanje)) {
                Sleep::for($this->cekanje($odgovor, (int) $cekanje[$pokusaj]))->seconds();

                continue;
            }

            if (! $odgovor->successful()) {
                throw new OllamaNedostupna(in_array($odgovor->status(), [401, 403], true) ? 'Claude API je odbio ključ (HTTP '.$odgovor->status().')' : 'Claude API je odgovorio sa HTTP '.$odgovor->status());
            }

            return $this->izvuci($odgovor);
        }
    }

    // Ako odgovor kaže koliko da se čeka (Retry-After), toliko, ali najviše 30 s.
    private function cekanje(Response $odgovor, int $podrazumevano): int
    {
        $trazeno = $odgovor->header('retry-after');

        return is_numeric($trazeno) ? min(30, max(0, (int) $trazeno)) : $podrazumevano;
    }

    private function izvuci(Response $odgovor): string
    {
        $this->poziva++;
        $this->ulazni += (int) $odgovor->json('usage.input_tokens');
        $this->izlazni += (int) $odgovor->json('usage.output_tokens');

        $tekst = '';

        foreach ((array) $odgovor->json('content') as $deo) {
            if (is_array($deo) && ($deo['type'] ?? null) === 'text' && is_string($deo['text'] ?? null)) {
                $tekst .= $deo['text'];
            }
        }

        return $tekst;
    }
}
