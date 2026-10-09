<?php

namespace App\Services;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\Latinica;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

// Uvozi poslove iz Jooble API-ja kao nacrte, isto kao RSS: ništa ne objavljuje i samo dodaje nove. Po dokumentaciji
// (help.jooble.org, „REST API documentation") poziv je POST na adresu sa ključem u putanji, pa se adresa nikad ne ispisuje.
final class UvozJooble
{
    public function uvezi(): RezultatUvoza
    {
        $kljuc = (string) config('jooble.kljuc');
        $najvise = (int) config('jooble.najvise_poslova');

        try {
            $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))
                ->withUserAgent(config('uvoz.korisnicki_agent'))
                ->acceptJson()
                ->post(rtrim((string) config('jooble.adresa'), '/').'/'.$kljuc, [
                    'keywords' => (string) config('jooble.kljucne_reci'),
                    'location' => (string) config('jooble.lokacija'),
                    'page' => 1,
                    'ResultOnPage' => $najvise,
                ]);
        } catch (ConnectionException) {
            return new RezultatUvoza(greska: 'Jooble ne odgovara');
        }

        if ($odgovor->status() === 403) {
            return new RezultatUvoza(greska: 'Jooble nije prihvatila ključ (HTTP 403)');
        }

        if (! $odgovor->successful()) {
            return new RezultatUvoza(greska: 'Jooble je odgovorila sa HTTP '.$odgovor->status());
        }

        if (strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
            return new RezultatUvoza(greska: 'odgovor je veći od dozvoljene veličine');
        }

        $poslovi = $odgovor->json('jobs');

        if (! is_array($poslovi)) {
            return new RezultatUvoza(greska: 'odgovor nije u očekivanom obliku');
        }

        return $this->sacuvaj(array_slice($poslovi, 0, $najvise));
    }

    /** @param  array<int|string, mixed>  $poslovi */
    private function sacuvaj(array $poslovi): RezultatUvoza
    {
        $novih = 0;
        $preskoceno = 0;

        try {
            foreach ($poslovi as $posao) {
                $podaci = is_array($posao) ? $this->podaci($posao) : null;

                if ($podaci === null || Prilika::query()->where('link_izvora', $podaci['link_izvora'])->exists()) {
                    $preskoceno++;

                    continue;
                }

                Prilika::query()->create($podaci);
                $novih++;
            }
        } catch (Throwable) {
            // Poruka izuzetka može da sadrži adresu sa ključem, pa se ne prepisuje.
            return new RezultatUvoza($novih, $preskoceno, 'neočekivana greška pri upisu');
        }

        return new RezultatUvoza($novih, $preskoceno);
    }

    /**
     * @param  array<string, mixed>  $posao
     * @return array<string, mixed>|null
     */
    private function podaci(array $posao): ?array
    {
        $naslov = $this->tekst($posao['title'] ?? '');
        $link = is_string($posao['link'] ?? null) ? trim($posao['link']) : '';

        if ($naslov === '' || strlen($link) > 2048 || filter_var($link, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $link)) {
            return null;
        }

        $opis = $this->tekst($posao['snippet'] ?? '');

        return [
            'naslov' => mb_substr($naslov, 0, 255),
            'vrsta' => VrstaPrilike::Drugo,
            'status' => StatusPrilike::Nacrt,
            'kratak_opis' => mb_strimwidth($opis === '' ? $naslov : $opis, 0, 300, '…'),
            'naziv_izvora' => (string) config('jooble.ime'),
            'link_izvora' => $link,
        ];
    }

    // Jooble u snippet stavlja oznake za isticanje reči; sajt piše latinicom i bez HTML-a.
    private function tekst(mixed $vrednost): string
    {
        if (! is_string($vrednost)) {
            return '';
        }

        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $vrednost) ?? '';
        // Oznake za isticanje reči su u sredini rečenice, pa se razmak ubacuje samo tamo gde se red lomi.
        $html = preg_replace('#<(br|/p|/li|/div)\b[^>]*>#i', ' ', $html) ?? '';
        $tekst = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Latinica::izCirilice(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? ''));
    }
}
