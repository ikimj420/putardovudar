<?php

namespace App\Support;

use App\Enums\Rod;
use App\Models\Sablon;

// Šablon + ono što je čovek upisao = tekst za štampu ili kopiranje. Radi samo u memoriji: ništa se ne čuva.
final class SklapanjeSablona
{
    // Prazno polje ne ostavlja „{ime}" u tekstu, nego liniju koju čovek posle štampe popuni olovkom.
    public const PRAZNO = '__________';

    public const NAJVISE_ZNAKOVA_POLJA = 2000;

    // Mesto sa dva oblika, muškim pa ženskim: {radio/radila} (paket 64). Ne meša se sa mestom za polje, koje nema kosu crtu.
    public const RODNI_OBLIK = '/\{(\p{L}[\p{L} ]*)\/(\p{L}[\p{L} ]*)\}/u';

    /** @return list<string> ključevi koji u tekstu stoje kao {kljuc}, redom, bez ponavljanja */
    public static function kljuceUTekstu(string $tekst): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $tekst, $nalazi);

        return array_values(array_unique($nalazi[1]));
    }

    // Tekst šablona u izabranom rodu; bez roda ostaju oba oblika („radio/radila"), da se vidi šta treba izabrati.
    public static function uRodu(string $tekst, ?Rod $rod): string
    {
        return (string) preg_replace_callback(self::RODNI_OBLIK, fn (array $oblici) => match ($rod) {
            Rod::Muski => $oblici[1],
            Rod::Zenski => $oblici[2],
            null => $oblici[1].'/'.$oblici[2],
        }, $tekst);
    }

    /**
     * Upisano, a za prazno polje koje ima „ako_prazno" (podrazumevana reč zadata uz polje u adminu) ta reč (paket 70).
     *
     * @param  array<string, mixed>  $unosi  ključ polja → šta je čovek upisao
     * @return array<string, mixed>
     */
    public static function unosiSaPodrazumevanim(Sablon $sablon, array $unosi): array
    {
        foreach (self::polja($sablon) as $kljuc => $polje) {
            $podrazumevano = self::ocisti($polje['ako_prazno'] ?? null);

            if ($podrazumevano !== '' && self::ocisti($unosi[$kljuc] ?? null) === '') {
                $unosi[$kljuc] = $podrazumevano;
            }
        }

        return $unosi;
    }

    /**
     * Tekst šablona u izabranom rodu, bez redova sa praznim opcionim poljem („opciono" uz polje u adminu), a i bez odeljka (reda ili više redova bez praznog reda između) koji
     * bi bez tog reda ostao bez ijednog mesta za polje, na primer naslova „Kursevi" iznad praznog polja. Mesta za polja ostaju u tekstu (paket 70).
     *
     * @param  array<string, mixed>  $unosi  ključ polja → šta je čovek upisao
     */
    public static function tekstSablona(Sablon $sablon, array $unosi, ?Rod $rod): string
    {
        $tekst = self::uRodu((string) $sablon->tekst, $rod);
        $izostavljena = [];

        foreach (self::polja($sablon) as $kljuc => $polje) {
            if (($polje['opciono'] ?? false) === true && self::ocisti($polje['ako_prazno'] ?? null) === '' && self::ocisti($unosi[$kljuc] ?? null) === '') {
                $izostavljena[] = $kljuc;
            }
        }

        return $izostavljena === [] ? $tekst : self::bezIzostavljenih(str_replace(["\r\n", "\r"], "\n", $tekst), $izostavljena);
    }

    /** @param  array<string, mixed>  $unosi  ključ polja → šta je čovek upisao */
    public static function sklopi(Sablon $sablon, array $unosi, ?Rod $rod = null): string
    {
        $vrednosti = [];
        $uneto = self::unosiSaPodrazumevanim($sablon, $unosi);

        foreach ($sablon->kljucevi() as $kljuc) {
            $vrednosti[$kljuc] = self::ocisti($uneto[$kljuc] ?? null);
        }

        // Jedan prolaz kroz tekst šablona: upisano se ne razvija ponovo, pa „{ime}" u polju ostaje kako je upisano.
        $tekst = preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn (array $mesto) => ($vrednosti[$mesto[1]] ?? '') !== '' ? $vrednosti[$mesto[1]] : self::PRAZNO, self::tekstSablona($sablon, $unosi, $rod));

        return str_replace(["\r\n", "\r"], "\n", (string) $tekst);
    }

    /** @return array<string, array<string, mixed>> ključ polja → polje */
    private static function polja(Sablon $sablon): array
    {
        $polja = [];

        foreach ((array) $sablon->polja as $polje) {
            if (is_array($polje) && is_string($polje['kljuc'] ?? null)) {
                $polja[$polje['kljuc']] = $polje;
            }
        }

        return $polja;
    }

    /** @param  list<string>  $izostavljena */
    private static function bezIzostavljenih(string $tekst, array $izostavljena): string
    {
        $pocetak = strlen($tekst) - strlen(ltrim($tekst, "\n"));
        $kraj = strlen($tekst) - strlen(rtrim($tekst, "\n"));
        $delovi = preg_split('/(\n[ \t]*\n(?:[ \t]*\n)*)/', trim($tekst, "\n"), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $ostalo = [];

        // Delovi su naizmenično odeljak i razmak iza njega (jedan ili više praznih redova).
        for ($i = 0; $i < count($delovi); $i += 2) {
            $redovi = explode("\n", $delovi[$i]);
            $ostaju = array_values(array_filter($redovi, fn (string $red) => array_intersect(self::mestaUReduu($red), $izostavljena) === []));
            $imaMesta = array_filter($ostaju, fn (string $red) => self::mestaUReduu($red) !== []) !== [];

            if (count($ostaju) !== count($redovi) && ! $imaMesta) {
                continue;
            }

            $ostalo[] = [implode("\n", $ostaju), $delovi[$i + 1] ?? ''];
        }

        $izlaz = '';

        foreach ($ostalo as $i => [$odeljak, $razmak]) {
            $izlaz .= $odeljak.($i < count($ostalo) - 1 ? $razmak : '');
        }

        return str_repeat("\n", $pocetak).$izlaz.str_repeat("\n", $kraj);
    }

    /** @return list<string> ključevi koji stoje kao {kljuc} u jednom redu */
    private static function mestaUReduu(string $red): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $red, $nalazi);

        return $nalazi[1];
    }

    private static function ocisti(mixed $vrednost): string
    {
        if (! is_string($vrednost)) {
            return '';
        }

        // Novi red ostaje (polje sa više redova), ostali upravljački znaci ispadaju.
        $vrednost = str_replace(["\r\n", "\r"], "\n", $vrednost);
        $vrednost = (string) preg_replace('/[^\P{C}\n]/u', '', $vrednost);

        return trim(mb_substr($vrednost, 0, self::NAJVISE_ZNAKOVA_POLJA));
    }
}
