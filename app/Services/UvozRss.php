<?php

namespace App\Services;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

// Uvozi jedan RSS 2.0 izvor kao nacrte. Ništa ne objavljuje i ne menja postojeće prilike: samo dodaje nove.
final class UvozRss
{
    public function uvezi(string $ime, string $adresa): RezultatUvoza
    {
        $novih = 0;
        $preskoceno = 0;

        try {
            $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))->withUserAgent(config('uvoz.korisnicki_agent'))->get($adresa);

            if (! $odgovor->successful()) {
                return new RezultatUvoza(greska: 'izvor je odgovorio sa HTTP '.$odgovor->status());
            }

            if (strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
                return new RezultatUvoza(greska: 'odgovor je veći od dozvoljene veličine');
            }

            $stavke = $this->stavke($odgovor->body());

            if ($stavke === null) {
                return new RezultatUvoza(greska: 'odgovor nije RSS');
            }

            foreach (array_slice($stavke, 0, config('uvoz.najvise_stavki')) as $stavka) {
                $podaci = $this->podaci($stavka, $ime);

                if ($podaci === null || Prilika::query()->where('link_izvora', $podaci['link_izvora'])->exists()) {
                    $preskoceno++;

                    continue;
                }

                Prilika::query()->create($podaci);
                $novih++;
            }
        } catch (Throwable $izuzetak) {
            return new RezultatUvoza($novih, $preskoceno, 'neočekivana greška: '.$izuzetak->getMessage());
        }

        return new RezultatUvoza($novih, $preskoceno);
    }

    /** @return list<SimpleXMLElement>|null */
    private function stavke(string $telo): ?array
    {
        $prethodno = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($telo, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prethodno);

        if ($xml === false || ! isset($xml->channel)) {
            return null;
        }

        $stavke = [];

        foreach ($xml->channel->item as $stavka) {
            $stavke[] = $stavka;
        }

        return $stavke;
    }

    /** @return array<string, mixed>|null */
    private function podaci(SimpleXMLElement $stavka, string $imeIzvora): ?array
    {
        $naslov = $this->tekst((string) $stavka->title);
        $link = trim((string) $stavka->link);

        if ($naslov === '' || strlen($link) > 2048 || filter_var($link, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $link)) {
            return null;
        }

        $opis = $this->tekst((string) $stavka->description);

        return [
            'naslov' => mb_substr($naslov, 0, 255),
            'vrsta' => VrstaPrilike::Drugo,
            'status' => StatusPrilike::Nacrt,
            'kratak_opis' => mb_strimwidth($opis === '' ? $naslov : $opis, 0, 300, '…'),
            'naziv_izvora' => $imeIzvora,
            'link_izvora' => $link,
        ];
    }

    private function tekst(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html) ?? '';
        $tekst = html_entity_decode(strip_tags(str_replace('>', '> ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? '');
    }
}
