<?php

namespace App\Services\Pomocnik;

use App\Enums\Rod;
use App\Models\Sablon;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\SklapanjeSablona;
use App\Support\ZapisGreske;
use Throwable;

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

    // Model rod dobija izričito, sa primerima oblika; primer vodiča može biti u drugom rodu, pa se prepisuje u ovaj.
    private const ROD = [
        'muski' => 'Rod autora je muški: piši u muškom rodu (radio sam, video sam, zadovoljan sam). Ako je primer u ženskom rodu, prepiši ga u muški.',
        'zenski' => 'Rod autora je ženski: piši u ženskom rodu (radila sam, videla sam, zadovoljna sam). Ako je primer u muškom rodu, prepiši ga u ženski.',
    ];

    public function __construct(private readonly Ollama $ollama, private readonly ProveraNapisanogTeksta $provera) {}

    /**
     * @param  array<string, string>  $unosi  ključ polja → šta je čovek upisao
     * @param  string|null  $primer  primer oblika iz vodiča; samo uzor, nikad izvor podataka
     * @param  Rod|null  $rod  rod u kom se piše; bez njega model ne dobija uputstvo o rodu
     */
    public function napisi(Sablon $sablon, array $unosi, ?string $primer = null, ?Rod $rod = null): NapisanTekst
    {
        $slozen = SklapanjeSablona::sklopi($sablon, $unosi, $rod);

        try {
            $tekst = Latinica::izCirilice($this->ollama->odgovoriTekstom(self::UPUTSTVO, $this->poruka($sablon, $unosi, $primer, $rod), (int) config('pomocnik.ukupno_za_pitanje')));
            $razlog = $this->provera->razlogOdbijanja($tekst, $this->dozvoljeno($sablon, $unosi, $rod));
        } catch (OllamaNedostupna) {
            return new NapisanTekst($slozen, false, 'model ne odgovara');
        } catch (Throwable $izuzetak) {
            // Bilo koja druga greška ne sme do rukovaoca izuzecima, koji u log piše poruku i trag poziva sa onim što je čovek upisao (paket 64).
            ZapisGreske::vrsta('Napiši moj', $izuzetak);

            return new NapisanTekst($slozen, false, 'neočekivana greška');
        }

        return $razlog === null ? new NapisanTekst($tekst, true) : new NapisanTekst($slozen, false, $razlog);
    }

    /** @param  array<string, string>  $unosi */
    private function poruka(Sablon $sablon, array $unosi, ?string $primer, ?Rod $rod): string
    {
        $poruka = "Šablon:\n".SklapanjeSablona::uRodu((string) $sablon->tekst, $rod);

        if (filled($primer)) {
            $poruka .= "\n\nPrimer oblika:\n".$primer;
        }

        if ($rod !== null) {
            $poruka .= "\n\n".self::ROD[$rod->value];
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
    private function dozvoljeno(Sablon $sablon, array $unosi, ?Rod $rod): string
    {
        return implode("\n", $unosi)."\n".preg_replace('/\{[a-z][a-z0-9_]*\}/', ' ', SklapanjeSablona::uRodu((string) $sablon->tekst, $rod));
    }
}
