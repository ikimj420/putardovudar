<?php

namespace App\Services\Pomocnik;

use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\DatumiUTekstu;
use App\Support\PoredjenjeTeksta;
use Illuminate\Support\Collection;

// Odgovor modela važi samo ako ne sadrži datum ili link kog nema u zapisima; inače ga menja šablon iz zapisa.
final class ProveraOdgovora
{
    public const NAJVISE_ZNAKOVA = 700;

    // „Nemam / nemaš / nema / nemamo ... podatak" u bilo kom licu i padežu, sa najviše jednom rečju između; poređenje je nad
    // normalizovanim tekstom (bez dijakritika, bez ćirilice). „Ni maš" je razdvojen oblik koji model ume da napiše.
    private const NEMA_PODATKA = '/\b(?:nem|ni ?m)(?:amo|ate|aju|am|as|a)\s+(?:\w+\s+)?(?:podat|informacij)/';

    private const DOMEN = '/(?<![\p{L}\d@.-])(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:rs|com|org|net|eu|info|edu|gov|me|io|co)(?:\/[^\s)\]»"„“]*)?(?![\p{L}\d])/iu';

    /**
     * Vraća razlog odbijanja, ili null kad je odgovor proveren.
     *
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     */
    public function razlogOdbijanja(string $odgovor, Collection $zapisi, Collection $vodici): ?string
    {
        if (trim($odgovor) === '') {
            return 'prazan odgovor';
        }

        if (mb_strlen($odgovor) > self::NAJVISE_ZNAKOVA) {
            return 'predug odgovor';
        }

        // Zapisi postoje, pa odgovor koji kaže da podatka nema protivreči kartici ispod njega.
        if (($zapisi->isNotEmpty() || $vodici->isNotEmpty()) && preg_match(self::NEMA_PODATKA, PoredjenjeTeksta::normalizuj($odgovor)) === 1) {
            return 'odgovor kaže da nema podatka';
        }

        if (! $this->datumiSuIzZapisa($odgovor, $zapisi, $vodici)) {
            return 'datum kog nema u zapisima';
        }

        if (! $this->linkoviSuIzZapisa($odgovor, $zapisi, $vodici)) {
            return 'link kog nema u zapisima';
        }

        return null;
    }

    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     */
    private function datumiSuIzZapisa(string $odgovor, Collection $zapisi, Collection $vodici): bool
    {
        $dozvoljeni = [];

        foreach ($vodici as $vodic) {
            array_push($dozvoljeni, ...DatumiUTekstu::izvuci($vodic->naslov.' '.$vodic->kratak_opis));
        }

        foreach ($zapisi as $prilika) {
            if ($prilika->rok !== null) {
                $dozvoljeni[] = ['dan' => $prilika->rok->day, 'mesec' => $prilika->rok->month, 'godina' => $prilika->rok->year];
            }

            array_push($dozvoljeni, ...DatumiUTekstu::izvuci($prilika->naslov.' '.$prilika->kratak_opis));
        }

        foreach (DatumiUTekstu::izvuci($odgovor) as $datum) {
            $postoji = false;

            foreach ($dozvoljeni as $dozvoljen) {
                $postoji = $postoji || ($dozvoljen['dan'] === $datum['dan'] && $dozvoljen['mesec'] === $datum['mesec'] && ($datum['godina'] === null || $datum['godina'] === $dozvoljen['godina']));
            }

            if (! $postoji) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     */
    private function linkoviSuIzZapisa(string $odgovor, Collection $zapisi, Collection $vodici): bool
    {
        $dozvoljeni = [];

        foreach ($vodici as $vodic) {
            $adresa = route('vodici.show', $vodic->slug);
            $dozvoljeni[] = $this->normalizuj($adresa);
            $dozvoljeni[] = $this->domen($adresa);
        }

        foreach ($zapisi as $prilika) {
            foreach ([$prilika->link_izvora, route('prilike.show', $prilika->slug)] as $adresa) {
                $dozvoljeni[] = $this->normalizuj((string) $adresa);
                $dozvoljeni[] = $this->domen((string) $adresa);
            }
        }

        preg_match_all(self::DOMEN, $odgovor, $nadjeni);

        foreach ($nadjeni[0] as $link) {
            if (! in_array($this->normalizuj($link), $dozvoljeni, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizuj(string $adresa): string
    {
        $adresa = mb_strtolower(trim($adresa));
        $adresa = preg_replace('~^https?://~', '', $adresa) ?? '';
        $adresa = preg_replace('~^www\.~', '', $adresa) ?? '';

        return rtrim($adresa, '/.,;:!?');
    }

    private function domen(string $adresa): string
    {
        return explode('/', $this->normalizuj($adresa))[0];
    }
}
