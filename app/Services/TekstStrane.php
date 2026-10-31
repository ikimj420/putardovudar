<?php

namespace App\Services;

use App\Support\Latinica;
use Throwable;

// Preuzima stranu izvora i vraća njen vidljiv tekst latinicom (uz izvorni HTML). Ista ograničenja kao uvoz (config/uvoz.php).
final class TekstStrane
{
    public function __construct(private readonly JavnoPreuzimanje $preuzimanje) {}

    /** @throws StranaNedostupna */
    public function preuzmi(string $adresa, int $najviseZnakova): PreuzetaStrana
    {
        try {
            $odgovor = $this->preuzimanje->preuzmi($adresa);
        } catch (StranaNedostupna $izuzetak) {
            throw $izuzetak;
        } catch (Throwable) {
            throw new StranaNedostupna('strana ne odgovara');
        }

        if (! $odgovor->successful()) {
            throw new StranaNedostupna('strana je odgovorila sa HTTP '.$odgovor->status(), $odgovor->status());
        }

        if (strlen($odgovor->body()) > config('uvoz.najvise_bajtova')) {
            throw new StranaNedostupna('strana je veća od dozvoljene veličine');
        }

        // Strana u drugoj kodnoj strani bi se pretvorila u prazan tekst („strana nema teksta"); bolje je da razlog piše. Izvori iz podešavanja su svi u UTF-8 (izmereno 13.10.2026.).
        if (! mb_check_encoding($odgovor->body(), 'UTF-8')) {
            throw new StranaNedostupna('strana nije u UTF-8');
        }

        // Infostud ostaje sa celom stranom: oglasi na engleskom nemaju „posao" u <article>, pa im se vrsta dokazuje okvirom sajta (PITANJA-76, izbor A).
        $tekst = self::izHtmla($odgovor->body(), ! RokSaInfostuda::jeInfostud($adresa));

        if ($tekst === '') {
            throw new StranaNedostupna('strana nema teksta');
        }

        return new PreuzetaStrana(mb_substr($tekst, 0, $najviseZnakova), $odgovor->body());
    }

    private static function clanak(string $html): string
    {
        if (preg_match_all('#<article(?=[\s>/])#i', $html) !== 1 || preg_match_all('#</article\s*>#i', $html) !== 1) {
            return $html;
        }

        return preg_match('#<article(?=[\s>/])[^>]*>(.*?)</article\s*>#is', $html, $clanak) === 1 ? $clanak[1] : $html;
    }

    // Oznake koje odvajaju tekst: blokovi, prelomi, tabele, liste, kontrole obrasca. Sve ostale (strong, em, span, a, b, i…) stoje usred reda i ne odvajaju ništa,
    // jer ih strane ume da zalepe usred reči („<strong>n</strong><strong>ovembar</strong>"); razmak koji piše između reči u HTML-u ostaje.
    private const BLOKOVI = 'p|div|br|hr|h[1-6]|ul|ol|li|dl|dt|dd|table|thead|tbody|tfoot|tr|td|th|caption|section|article|header|footer|main|aside|nav|blockquote|pre|form|fieldset|legend|figure|figcaption|address|details|summary|select|option|textarea|button|input|iframe|video|audio|canvas|object';

    // Meni, bočne trake i podnožje sajta (u div-ovima, ne u nav/footer) nisu tekst poziva, a model i provera citata bi ih čitali kao dokaz. Sa $samoClanak, kad strana ima tačno jedan
    // <article> (otvoren i zatvoren po jednom), čita se samo on; inače cela strana (paket 76). Oznaka je <article> samo ako iza imena sledi razmak, „>" ili „/".
    public static function izHtmla(string $html, bool $samoClanak = false): string
    {
        if ($samoClanak) {
            $html = self::clanak($html);
        }

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

        // Po znakovima, ne po bajtovima: trim() sa maskom seče prvi bajt znaka koji počinje isto kao „ “ ” (E2: ⏰ ✅ ➡) i ostavlja nevažeći UTF-8 (paket 74).
        return preg_replace('/^[ \t\n\r"\'„“”‘’]+|[ \t\n\r"\'„“”‘’]+$/u', '', mb_strtolower($tekst)) ?? '';
    }
}
