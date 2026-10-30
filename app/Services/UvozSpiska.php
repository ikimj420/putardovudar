<?php

namespace App\Services;

use App\Enums\StatusPrilike;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Support\Latinica;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

// Uvozi spisak poziva sa HTML strane koja nema RSS (Nacionalna služba za zapošljavanje, Opre Roma Srbija, Ministarstvo turizma i omladine) kao nacrte, istim poljima kao UvozRss; dalje ide ista obrada.
// Ništa ne objavljuje i ne menja postojeće prilike. Čita se samo prva strana spiska, a „ZATVOREN" u naslovu znači da poziv više ne važi.
final class UvozSpiska
{
    // Izgled strane je zasebno od izvora: gde je stavka, gde je veza naslova i gde datum objave (null kad strana datum nema ili ga piše bez godine, pa se rok bez godine ne prima).
    public const IZGLEDI = [
        'nsz' => [
            'stavka' => "//article[contains(concat(' ', normalize-space(@class), ' '), ' news-item ')]",
            'veza' => ".//*[contains(concat(' ', normalize-space(@class), ' '), ' news-item-title ')]//a",
            'datum' => ".//*[contains(concat(' ', normalize-space(@class), ' '), ' time-published ')]",
        ],
        // Ministarstvo turizma i omladine: stavka je red u spisku „article-list", bez datuma objave na spisku.
        'mto' => [
            'stavka' => "//ul[contains(concat(' ', normalize-space(@class), ' '), ' article-list ')]/li",
            'veza' => './/a',
            'datum' => null,
        ],
        'opre-roma' => [
            'stavka' => "//div[contains(concat(' ', normalize-space(@class), ' '), ' ul-blog ')]",
            'veza' => ".//a[contains(concat(' ', normalize-space(@class), ' '), ' ul-blog-title ')]",
            'datum' => null,
        ],
    ];

    public function __construct(private readonly DopunaDatumaObjave $dopuna = new DopunaDatumaObjave) {}

    public function uvezi(string $ime, string $adresa, string $izgled = 'nsz'): RezultatUvoza
    {
        if (! isset(self::IZGLEDI[$izgled])) {
            return new RezultatUvoza(greska: 'nepoznat izgled strane: '.$izgled);
        }

        $novih = 0;
        $preskoceno = 0;
        $dopunjeno = 0;

        try {
            $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))->withUserAgent(config('uvoz.korisnicki_agent'))->get($adresa);

            if (! $odgovor->successful()) {
                return new RezultatUvoza(greska: 'izvor je odgovorio sa HTTP '.$odgovor->status());
            }

            if (strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
                return new RezultatUvoza(greska: 'odgovor je veći od dozvoljene veličine');
            }

            $stavke = $this->stavke($odgovor->body(), self::IZGLEDI[$izgled]['stavka']);

            // Spisak se čita po izgledu strane; kad se izgled promeni, nijedna stavka se ne nalazi i to se vidi u ispisu.
            if ($stavke === []) {
                return new RezultatUvoza(greska: 'spisak nije pročitan, izgled strane se verovatno promenio');
            }

            foreach (array_slice($stavke, 0, config('uvoz.najvise_stavki')) as $stavka) {
                $podaci = $this->podaci($stavka, $ime, $adresa, self::IZGLEDI[$izgled]);

                if ($podaci === null) {
                    $preskoceno++;

                    continue;
                }

                // Stari nacrt bez datuma objave ga dobija (paket 70).
                $postojeca = Prilika::query()->where('link_izvora', $podaci['link_izvora'])->first();

                if ($postojeca !== null) {
                    $dopunjeno += $this->dopuna->dopuni($postojeca, $podaci['izvor_objavljen']) ? 1 : 0;
                    $preskoceno++;

                    continue;
                }

                Prilika::query()->create($podaci);
                $novih++;
            }
        } catch (Throwable $izuzetak) {
            return new RezultatUvoza($novih, $preskoceno, 'neočekivana greška: '.$izuzetak->getMessage(), $dopunjeno);
        }

        return new RezultatUvoza($novih, $preskoceno, dopunjeno: $dopunjeno);
    }

    /** @return list<DOMElement> */
    private function stavke(string $telo, string $izraz): array
    {
        $dokument = new DOMDocument;
        $prethodno = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="UTF-8">'.$telo, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prethodno);

        $stavke = [];

        foreach ((new DOMXPath($dokument))->query($izraz) ?: [] as $stavka) {
            if ($stavka instanceof DOMElement) {
                $stavke[] = $stavka;
            }
        }

        return $stavke;
    }

    /**
     * @param  array{stavka: string, veza: string, datum: ?string}  $izgled
     * @return array<string, mixed>|null
     */
    private function podaci(DOMElement $stavka, string $imeIzvora, string $adresaSpiska, array $izgled): ?array
    {
        $xpath = new DOMXPath($stavka->ownerDocument);
        $veza = $this->prvi($xpath, $izgled['veza'], $stavka);

        if (! $veza instanceof DOMElement) {
            return null;
        }

        $naslov = Latinica::izCirilice(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $veza->textContent)));
        $link = $this->apsolutno(trim($veza->getAttribute('href')), $adresaSpiska);

        // „ZATVOREN" na kraju naslova kaže da poziv više ne važi; takav se ne uvozi.
        if ($naslov === '' || $link === null || preg_match('/\bzatvoren\w*/iu', $naslov) === 1) {
            return null;
        }

        $objavljeno = $izgled['datum'] === null ? null : $this->prvi($xpath, $izgled['datum'], $stavka);

        return [
            'naslov' => mb_substr($naslov, 0, 255),
            'vrsta' => VrstaPrilike::Drugo,
            'status' => StatusPrilike::Nacrt,
            'kratak_opis' => mb_strimwidth($naslov, 0, 300, '…'),
            'naziv_izvora' => $imeIzvora,
            'link_izvora' => $link,
            'izvor_objavljen' => $this->objavljeno($objavljeno instanceof DOMNode ? $objavljeno->textContent : ''),
        ];
    }

    private function prvi(DOMXPath $xpath, string $izraz, DOMElement $unutar): ?DOMNode
    {
        $nadjeno = $xpath->query($izraz, $unutar);

        return $nadjeno === false ? null : $nadjeno->item(0);
    }

    // Veza na istom sajtu može da stigne bez adrese sajta; šta nije http(s) ili je predugo, ne uvozi se.
    private function apsolutno(string $veza, string $adresaSpiska): ?string
    {
        if (str_starts_with($veza, '/') && ! str_starts_with($veza, '//')) {
            $delovi = parse_url($adresaSpiska);
            $veza = ($delovi['scheme'] ?? 'https').'://'.($delovi['host'] ?? '').$veza;
        }

        // Sajt piše i crticu „–" u adresi; pregledač je šalje kodiranu, pa se tako i čuva.
        $veza = (string) preg_replace_callback('/[^\x21-\x7e]/u', fn (array $znak) => rawurlencode($znak[0]), $veza);

        if (strlen($veza) > 2048 || filter_var($veza, FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#i', $veza)) {
            return null;
        }

        return $veza;
    }

    // Datum objave piše „dd.mm.yyyy."; šta se ne može pročitati ostaje prazno, pa se rok bez godine tada ne prima.
    private function objavljeno(string $datum): ?string
    {
        if (preg_match('/^\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\.?\s*$/', $datum, $deo) !== 1 || ! checkdate((int) $deo[2], (int) $deo[1], (int) $deo[3])) {
            return null;
        }

        return Carbon::create((int) $deo[3], (int) $deo[2], (int) $deo[1], 0, 0, 0, config('app.timezone'))->toDateString();
    }
}
