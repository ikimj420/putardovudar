<?php

namespace App\Services;

use App\Support\Latinica;
use Illuminate\Support\Facades\Http;
use Throwable;

// Preuzima stranu izvora i vraća njen vidljiv tekst latinicom (uz izvorni HTML). Ista ograničenja kao uvoz (config/uvoz.php).
final class TekstStrane
{
    /** @throws StranaNedostupna */
    public function preuzmi(string $adresa, int $najviseZnakova): PreuzetaStrana
    {
        try {
            $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))->withUserAgent(config('uvoz.korisnicki_agent'))->get($adresa);
        } catch (Throwable) {
            throw new StranaNedostupna('strana ne odgovara');
        }

        if (! $odgovor->successful()) {
            throw new StranaNedostupna('strana je odgovorila sa HTTP '.$odgovor->status());
        }

        if (strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
            throw new StranaNedostupna('strana je veća od dozvoljene veličine');
        }

        $tekst = self::izHtmla($odgovor->body());

        if ($tekst === '') {
            throw new StranaNedostupna('strana nema teksta');
        }

        return new PreuzetaStrana(mb_substr($tekst, 0, $najviseZnakova), $odgovor->body());
    }

    // Oznake koje odvajaju tekst: blokovi, prelomi, tabele, liste, kontrole obrasca. Sve ostale (strong, em, span, a, b, i…) stoje usred reda i ne odvajaju ništa,
    // jer ih strane ume da zalepe usred reči („<strong>n</strong><strong>ovembar</strong>"); razmak koji piše između reči u HTML-u ostaje.
    private const BLOKOVI = 'p|div|br|hr|h[1-6]|ul|ol|li|dl|dt|dd|table|thead|tbody|tfoot|tr|td|th|caption|section|article|header|footer|main|aside|nav|blockquote|pre|form|fieldset|legend|figure|figcaption|address|details|summary|select|option|textarea|button|input|iframe|video|audio|canvas|object';

    public static function izHtmla(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|head|nav|footer)\b.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#</?(?:'.self::BLOKOVI.')\b[^>]*>#i', ' ', $html) ?? '';
        $tekst = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Latinica::izCirilice(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? ''));
    }

    // Poređenje citata sa stranom ne zavisi od velikih slova, razmaka i navodnika oko citata, ni od razmaka ispred interpunkcije koji strana ima iz
    // izvlačenja teksta („vremenu ." na strani, „vremenu." u citatu) i iza otvorene zagrade.
    public static function normalizuj(string $tekst): string
    {
        $tekst = preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? '';
        $tekst = preg_replace('/ ([.,;:!?)])/u', '$1', $tekst) ?? '';
        $tekst = preg_replace('/\( /u', '(', $tekst) ?? '';

        return trim(mb_strtolower($tekst), " \t\n\r\"'„“”‘’");
    }
}
