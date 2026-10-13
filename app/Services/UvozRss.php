<?php

namespace App\Services;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\Latinica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

// Uvozi jedan RSS 2.0 izvor kao nacrte. Ništa ne objavljuje i ne menja postojeće prilike: samo dodaje nove.
final class UvozRss
{
    // Infostud u RSS-u daje http, a oglas se otvara i preko https: veza se čuva kao https (paket 64, PITANJA-58-61.md 4). Jedino mesto sa tim pravilom.
    private const INFOSTUD_HTTP = '#^http://(poslovi\.infostud\.com/)#i';

    private const INFOSTUD_HTTPS = '#^https://(poslovi\.infostud\.com/)#i';

    public function __construct(private readonly DopunaDatumaObjave $dopuna = new DopunaDatumaObjave) {}

    // Čita strane izvora redom (?paged=2…, WordPress), najviše koliko piše u podešavanju. Prva strana je kao do sada (greška se prijavljuje); druga i dalje staju tiho na prvoj
    // koja ne stigne, nije RSS, nema stavki ili nema nijednu novu vezu (izvor koji ne zna „paged" vraća istu stranu).
    public function uvezi(string $ime, string $adresa, ?int $najviseStrana = null): RezultatUvoza
    {
        $najviseStrana = max(1, $najviseStrana ?? (int) config('uvoz.najvise_strana', 1));
        $novih = 0;
        $preskoceno = 0;
        $dopunjeno = 0;
        $videne = [];

        try {
            for ($strana = 1; $strana <= $najviseStrana; $strana++) {
                $stavke = $strana === 1 ? $this->prvaStrana($adresa) : $this->sledecaStrana($adresa, $strana);

                if ($stavke instanceof RezultatUvoza) {
                    return $stavke;
                }

                $linkovi = array_map(fn (SimpleXMLElement $stavka) => trim((string) $stavka->link), $stavke);

                if ($stavke === [] || ($strana > 1 && array_diff($linkovi, $videne) === [])) {
                    break;
                }

                $videne = [...$videne, ...$linkovi];

                foreach (array_slice($stavke, 0, config('uvoz.najvise_stavki')) as $stavka) {
                    $podaci = $this->podaci($stavka, $ime);

                    if ($podaci === null) {
                        $preskoceno++;

                        continue;
                    }

                    // Oglas uvezen ranije sa http adresom je isti oglas: ne uvozi se ponovo samo zato što se veza sad čuva kao https. Stari nacrt bez datuma objave ga dobija (paket 70).
                    $postojeca = Prilika::query()->whereIn('link_izvora', [$podaci['link_izvora'], preg_replace(self::INFOSTUD_HTTPS, 'http://$1', $podaci['link_izvora'])])->first();

                    if ($postojeca !== null) {
                        $dopunjeno += $this->dopuna->dopuni($postojeca, $podaci['izvor_objavljen']) ? 1 : 0;
                        $preskoceno++;

                        continue;
                    }

                    Prilika::query()->create($podaci);
                    $novih++;
                }
            }
        } catch (Throwable $izuzetak) {
            return new RezultatUvoza($novih, $preskoceno, 'neočekivana greška: '.$izuzetak->getMessage(), $dopunjeno);
        }

        return new RezultatUvoza($novih, $preskoceno, dopunjeno: $dopunjeno);
    }

    /** @return list<SimpleXMLElement>|RezultatUvoza stavke prve strane ili greška uvoza */
    private function prvaStrana(string $adresa): array|RezultatUvoza
    {
        $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))->withUserAgent(config('uvoz.korisnicki_agent'))->get($adresa);

        if (! $odgovor->successful()) {
            return new RezultatUvoza(greska: 'izvor je odgovorio sa HTTP '.$odgovor->status());
        }

        if (strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
            return new RezultatUvoza(greska: 'odgovor je veći od dozvoljene veličine');
        }

        return $this->stavke($odgovor->body()) ?? new RezultatUvoza(greska: 'odgovor nije RSS');
    }

    /** @return list<SimpleXMLElement> stavke strane, a prazno kad strane nema ili ne valja (uvoz tada staje) */
    private function sledecaStrana(string $adresa, int $strana): array
    {
        try {
            $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))->withUserAgent(config('uvoz.korisnicki_agent'))->get($adresa.(str_contains($adresa, '?') ? '&' : '?').'paged='.$strana);
        } catch (Throwable) {
            return [];
        }

        if (! $odgovor->successful() || strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
            return [];
        }

        return $this->stavke($odgovor->body()) ?? [];
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
            'link_izvora' => preg_replace(self::INFOSTUD_HTTP, 'https://$1', $link),
            'izvor_objavljen' => $this->objavljeno((string) $stavka->pubDate),
        ];
    }

    // Dan objave u zoni aplikacije; šta se ne može pročitati ostaje prazno, pa se rok bez godine tada ne prima. Neki izvori pišu i datum ćirilicom
    // (dan u nedelji i mesec), pa se pretvara u latinicu pre čitanja.
    private function objavljeno(string $pubDate): ?string
    {
        if (trim($pubDate) === '') {
            return null;
        }

        try {
            return Carbon::parse(Latinica::izCirilice($pubDate))->setTimezone(config('app.timezone'))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function tekst(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html) ?? '';
        $tekst = html_entity_decode(strip_tags(str_replace('>', '> ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Neki izvori pišu ćirilicom, a sajt piše samo latinicom; pretvara se pre sečenja na dužinu polja.
        return Latinica::izCirilice(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? ''));
    }
}
