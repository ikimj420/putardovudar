<?php

namespace App\Services\Pomocnik;

use App\Models\Sablon;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\SklapanjeSablona;

// „Napiši moj": model piše tekst po šablonu iz onoga što je čovek upisao, a kod proverava šta je napisao. Šta ne prođe proveru
// (ili model ne radi), zamenjuje šablon sklopljen sa odgovorima. Ništa se ne čuva.
final class PisanjeSablona
{
    private const UPUTSTVO = <<<'TEXT'
Napiši tekst po šablonu, na srpskom jeziku latinicom. Vrati samo taj tekst.
Drži se oblika šablona i primera, ali iz primera ne prepisuj ništa.
Koristi samo ono što je čovek upisao. Ne dodaj iskustvo, škole, imena, datume, brojeve, adrese ni linkove kojih nema u upisanom.
Ono što nije upisano izostavi.
TEXT;

    public function __construct(private readonly Ollama $ollama, private readonly ProveraNapisanogTeksta $provera) {}

    /**
     * @param  array<string, string>  $unosi  ključ polja → šta je čovek upisao
     * @param  string|null  $primer  primer oblika iz vodiča; samo uzor, nikad izvor podataka
     */
    public function napisi(Sablon $sablon, array $unosi, ?string $primer = null): NapisanTekst
    {
        $slozen = SklapanjeSablona::sklopi($sablon, $unosi);

        try {
            $tekst = Latinica::izCirilice($this->ollama->odgovoriTekstom(self::UPUTSTVO, $this->poruka($sablon, $unosi, $primer), (int) config('pomocnik.ukupno_za_pitanje')));
        } catch (OllamaNedostupna) {
            return new NapisanTekst($slozen, false, 'model ne odgovara');
        }

        $razlog = $this->provera->razlogOdbijanja($tekst, $this->dozvoljeno($sablon, $unosi));

        return $razlog === null ? new NapisanTekst($tekst, true) : new NapisanTekst($slozen, false, $razlog);
    }

    /** @param  array<string, string>  $unosi */
    private function poruka(Sablon $sablon, array $unosi, ?string $primer): string
    {
        $poruka = "Šablon:\n".$sablon->tekst;

        if (filled($primer)) {
            $poruka .= "\n\nPrimer oblika:\n".$primer;
        }

        $upisano = [];

        foreach ((array) $sablon->polja as $polje) {
            $kljuc = is_array($polje) ? ($polje['kljuc'] ?? null) : null;
            $vrednost = is_string($kljuc) ? trim($unosi[$kljuc] ?? '') : '';

            if ($vrednost !== '') {
                $upisano[] = '{'.$kljuc.'} ('.($polje['oznaka'] ?? $kljuc).'): '.$vrednost;
            }
        }

        return $poruka."\n\nUpisano:\n".implode("\n", $upisano);
    }

    // Model sme da ponovi ono što je čovek upisao i stalni tekst šablona (tekst bez mesta za polja); primer nije izvor.
    /** @param  array<string, string>  $unosi */
    private function dozvoljeno(Sablon $sablon, array $unosi): string
    {
        return implode("\n", $unosi)."\n".preg_replace('/\{[a-z][a-z0-9_]*\}/', ' ', (string) $sablon->tekst);
    }
}
