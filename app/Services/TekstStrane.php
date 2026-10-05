<?php

namespace App\Services;

use App\Support\Latinica;
use Illuminate\Support\Facades\Http;
use Throwable;

// Preuzima stranu izvora i vraća njen vidljiv tekst latinicom. Ista ograničenja kao uvoz (config/uvoz.php).
final class TekstStrane
{
    /** @throws StranaNedostupna */
    public function preuzmi(string $adresa, int $najviseZnakova): string
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

        return mb_substr($tekst, 0, $najviseZnakova);
    }

    public static function izHtmla(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|head|nav|footer)\b.*?</\1>#is', ' ', $html) ?? '';
        $tekst = html_entity_decode(strip_tags(str_replace('>', '> ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Latinica::izCirilice(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? ''));
    }

    // Poređenje citata sa stranom ne zavisi od velikih slova, razmaka i navodnika oko citata.
    public static function normalizuj(string $tekst): string
    {
        return trim(mb_strtolower(preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst) ?? ''), " \t\n\r\"'„“”‘’");
    }
}
