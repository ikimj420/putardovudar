<?php

namespace App\Support;

use App\Enums\Grupa;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;

// Tekstovi o prilici koje vidi posetilac, na jednom mestu, da kartica i strana ne pišu isto na dva načina.
final class PrikazPrilike
{
    public const NEMA_PRILIKA = 'Trenutno nema otvorenih prilika.';

    // Pretraga (paket 57): tekstovi su iz predloga izgleda; Ivan odobrava (PITANJA-57.md).
    public const PRETRAGA_NAZIV = 'Pretraga';

    public const PRETRAGA_PRIMER = 'Šta tražiš? Npr. stipendija, posao u Nišu…';

    public const PRETRAGA_DUGME = 'Traži';

    public const NEMA_REZULTATA = 'Nema prilika za tu pretragu.';

    // Nazivi vrsta u množini, za natpis iznad naslova na početnoj (paket 57); „Drugo" nema množinu, pa se tamo ne piše.
    public const VRSTE_U_MNOZINI = [
        'posao' => 'Poslovi', 'praksa' => 'Prakse', 'stipendija' => 'Stipendije', 'konkurs' => 'Konkursi', 'obuka' => 'Obuke',
    ];

    public const SVE_PRILIKE = 'Sve prilike';

    // Naziv grupe dugmadi za filter, čita ga čitač ekrana; predlog je u PITANJA-26-38.md.
    public const FILTERI = 'Brzi filteri';

    public const NEMA_STRANE = 'Ta strana ne postoji ili prilika više nije otvorena.';

    public const ZVANICNI_IZVOR = 'Zvanični izvor: ';

    public const PROVERI_IZVOR = 'Pre prijave proveri podatke na zvaničnom izvoru.';

    // Oznaka grupa na kartici, strani prilike i u adminu; tekst je iz paketa 39.
    public const KOME = 'Kome je namenjeno';

    public const BEZ_ROKA = 'Bez roka';

    public const STALNO_OTVORENO = 'Prijave stalno otvorene';

    // Oznaka na kartici i strani prilike (paket 57); računa se iz roka, a prilika bez roka i stalno otvorena je nema.
    public const ZATVARA_SE_DANAS = 'Zatvara se danas';

    public const ZATVARA_SE_ZA = 'Zatvara se za ';

    public const DAN = ['dan', 'dana', 'dana'];

    // Oznaka na kartici u adminu; sajt je ne piše jer prilike sa prošlim rokom ne prikazuje.
    public const ROK_PROSAO = 'Rok prošao';

    // Dugme na strani prilike i događaj u kalendaru telefona; tekstovi su predlog (PITANJA-46-52.md, paket 49).
    public const DODAJ_ROK = 'Dodaj rok u telefon';

    public const ROK_U_KALENDARU = 'Rok: ';

    // Rok se prenosi u telefon samo kad postoji i važi; za stalno otvorene prijave i prilike bez roka nema šta da se doda.
    public static function imaRokZaTelefon(Prilika $prilika): bool
    {
        return $prilika->rok !== null && ! $prilika->rok_stalno_otvoren;
    }

    public static function rok(Prilika $prilika): string
    {
        if ($prilika->rok_stalno_otvoren) {
            return self::STALNO_OTVORENO;
        }

        return $prilika->rok === null ? self::BEZ_ROKA : 'Rok: '.Prikaz::datum($prilika->rok);
    }

    // „Zatvara se za 5 dana", „Zatvara se za 1 dan", „Zatvara se danas"; bez roka, uz stalno otvorene prijave i kad je rok prošao nema oznake.
    public static function zatvaraSe(Prilika $prilika): ?string
    {
        if ($prilika->rok === null || $prilika->rok_stalno_otvoren) {
            return null;
        }

        $dana = Prikaz::danaDo($prilika->rok);

        return match (true) {
            $dana < 0 => null,
            $dana === 0 => self::ZATVARA_SE_DANAS,
            default => self::ZATVARA_SE_ZA.$dana.' '.Prikaz::oblik($dana, self::DAN),
        };
    }

    // Adresa spiska sa izabranom vrstom i upisanom pretragom; bez njih je običan spisak. Grupe se na sajtu ne biraju (paket 54).
    public static function adresaSpiska(?VrstaPrilike $vrsta = null, ?string $upit = null): string
    {
        return route('prilike.index', array_filter(['vrsta' => $vrsta?->value, 'q' => $upit]));
    }

    // Nazivi grupa jednim redom, ili null kad nema nijedne (tada se ništa ne piše); isto za priliku i za stavku kalendara.
    /** @param  list<Grupa>  $grupe */
    public static function nazivi(array $grupe): ?string
    {
        $nazivi = array_map(fn (Grupa $grupa) => $grupa->getLabel(), $grupe);

        return $nazivi === [] ? null : implode(', ', $nazivi);
    }

    public static function grupe(Prilika $prilika): ?string
    {
        return self::nazivi($prilika->izabraneGrupe());
    }

    // „Online" ima prednost: polje Online znači da se sve radi preko interneta, pa mesto nije bitno.
    public static function mesto(Prilika $prilika): ?string
    {
        if ($prilika->online) {
            return 'Online';
        }

        return filled($prilika->mesto) ? $prilika->mesto : null;
    }

    // Link se piše samo ako je veb adresa: izvor u bazi može da stigne i mimo pravila objave (masovni upit).
    public static function linkIzvora(Prilika $prilika): ?string
    {
        $link = trim((string) $prilika->link_izvora);

        return str_starts_with($link, 'http://') || str_starts_with($link, 'https://') ? $link : null;
    }

    public static function nazivIzvora(Prilika $prilika): ?string
    {
        if (filled($prilika->naziv_izvora)) {
            return $prilika->naziv_izvora;
        }

        $adresa = self::linkIzvora($prilika);

        return $adresa === null ? null : (parse_url($adresa, PHP_URL_HOST) ?: $adresa);
    }
}
