<?php

namespace Tests\Concerns;

// Svi tekstovi koje čita posetilac: konstante i tekstualni nizovi u app/Support/Prikaz*.php, i stalni tekst javnih strana (resources/views/javno
// i errors). Dinamički delovi ({{ }}, {!! !!}, @direktive) nisu tekst, pa se ne gledaju.
trait TekstoviZaPosetioca
{
    /** @return list<array{izvor: string, tekst: string}> */
    protected function tekstoviZaPosetioca(): array
    {
        $koren = dirname(__DIR__, 2);
        $tekstovi = [];

        foreach (glob($koren.'/app/Support/Prikaz*.php') ?: [] as $fajl) {
            foreach ($this->nizoviIzKoda((string) file_get_contents($fajl)) as [$linija, $tekst]) {
                $tekstovi[] = ['izvor' => 'app/Support/'.basename($fajl).':'.$linija, 'tekst' => $tekst];
            }
        }

        foreach ([...(glob($koren.'/resources/views/javno/*.blade.php') ?: []), ...(glob($koren.'/resources/views/errors/*.blade.php') ?: [])] as $fajl) {
            foreach ($this->stalniTekstIzPogleda((string) file_get_contents($fajl)) as [$linija, $tekst]) {
                $tekstovi[] = ['izvor' => substr($fajl, strlen($koren) + 1).':'.$linija, 'tekst' => $tekst];
            }
        }

        return $tekstovi;
    }

    /** @return list<array{0: int, 1: string}> nizovi iz koda koji liče na tekst za čoveka (reč od tri slova, i razmak ili veliko početno slovo) */
    protected function nizoviIzKoda(string $kod): array
    {
        $nadjeno = [];

        foreach (token_get_all($kod) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $nav = $token[1][0];
            $tekst = substr($token[1], 1, -1);
            $tekst = $nav === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $tekst) : stripcslashes($tekst);

            if (preg_match('/\p{L}{3,}/u', $tekst) === 1 && ! str_contains($tekst, '\\') && ! str_contains($tekst, '/') && (str_contains($tekst, ' ') || preg_match('/^\p{Lu}/u', $tekst) === 1)) {
                $nadjeno[] = [$token[2], $tekst];
            }
        }

        return $nadjeno;
    }

    /** @return list<array{0: int, 1: string}> */
    protected function stalniTekstIzPogleda(string $pogled): array
    {
        $pogled = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $pogled);
        $pogled = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $pogled);
        $nadjeno = [];

        foreach (explode("\n", $pogled) as $broj => $red) {
            $red = trim($red);

            if ($red === '' || str_starts_with($red, '@')) {
                continue;
            }

            // Direktiva usred reda (@isset(...), @endisset) nije tekst; zagrade u njoj mogu da budu ugnežđene.
            $red = (string) preg_replace('/@[a-z]+(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/', ' ', $red);

            // Tekst u atributima koje čita čitač ekrana ili vidi posetilac (bez dinamičkog dela).
            preg_match_all('/\b(?:aria-label|placeholder|title|alt)="([^"]+)"/', $red, $atributi);
            $tekst = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($red))));

            foreach ([$tekst, ...$atributi[1]] as $deo) {
                if (preg_match('/\p{L}{3,}/u', $deo) === 1) {
                    $nadjeno[] = [$broj + 1, $deo];
                }
            }
        }

        return $nadjeno;
    }

    /** @return list<string> */
    protected function recenice(string $tekst): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?…])\s+/u', $tekst) ?: []), fn (string $r) => $r !== ''));
    }

    // Najviše reči u rečenici i reči koje običan čovek ne mora da zna (predlog; Ivan odobrava, PITANJA-46-52.md, paket 52). „Sajt", „Viber"
    // i „WhatsApp" nisu na spisku: prve dve su ovde u običnoj upotrebi, a ostale su imena. „Link" i „linkovi" su skinuti sa spiska (Ivan, 30.10.2026.: „link" ostaje).
    protected const NAJVISE_RECI_U_RECENICI = 15;

    protected const STRANE_RECI = '/^(?:online|filter\w*|mejl\w*|e-?mail\w*|web|download\w*|klik\w*|info|feed|rss|api|pdf|ics|url|chat|cookies?|login|logout|update|upload|template|widget)$/u';

    /** @return list<string> razlozi zbog kojih tekst nije jednostavan: „duga rečenica (N reči)" i „strana reč: X" */
    protected function razloziZaNalaz(string $tekst): array
    {
        $razlozi = [];

        foreach ($this->recenice($tekst) as $recenica) {
            if ($this->brojReci($recenica) > self::NAJVISE_RECI_U_RECENICI) {
                $razlozi[] = 'duga rečenica ('.$this->brojReci($recenica).' reči)';
            }
        }

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $tekst, $reci);

        foreach ($reci[0] as $rec) {
            if (preg_match(self::STRANE_RECI, mb_strtolower($rec)) === 1) {
                $razlozi[] = 'strana reč: '.mb_strtolower($rec);
            }
        }

        return array_values(array_unique($razlozi));
    }

    protected function brojReci(string $recenica): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $recenica);
    }
}
