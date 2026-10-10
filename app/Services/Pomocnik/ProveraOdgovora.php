<?php

namespace App\Services\Pomocnik;

use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\DatumiUTekstu;
use App\Support\PoredjenjeTeksta;
use App\Support\PrikazOrganizacija;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Odgovor modela važi samo ako ne sadrži datum ili link kog nema u zapisima; inače ga menja šablon iz zapisa.
final class ProveraOdgovora
{
    public const NAJVISE_ZNAKOVA = 700;

    // Oblici „nema podatka", poređeni nad normalizovanim tekstom (bez dijakritika, bez ćirilice), jedna rečenica ili deo posle
    // zareza odjednom. Između su najviše dve reči, ali nikad veznik: „nema roka ali podatak postoji" nije „nema podatka".
    // Iza „nema" nije ni predlog („nema veze sa informacijama"), a pre „nema" jeste dozvoljen („podatka o tome nema").
    // „Ni maš" je razdvojen oblik koji model ume da napiše.
    private const VEZNICI = 'ali|pa|a|no|nego|jer|dok|i|ili|te|niti|ni';

    private const PREDLOZI = 'za|sa|o|u|na|od|do|po|uz|kod|iz|pri|oko|bez';

    // „Informacije nema potrebe" je savet, ne tvrdnja o zapisima.
    private const NEMA_SAVET = 'potrebe|veze|smisla|razloga|problema|ogranicenja|prepreka';

    private const IZMEDJU_POSLE_NEMA = '(?:(?!(?:'.self::VEZNICI.'|'.self::PREDLOZI.')\b)\w+\s+)';

    private const IZMEDJU_PRE_NEMA = '(?:(?!(?:'.self::VEZNICI.')\b)\w+\s+)';

    private const NEMA_PODATKA = [
        // „Nemam / nemaš / nema / nemamo ... podatak ili informacija" u bilo kom licu i padežu.
        '/\b(?:nem|ni ?m)(?:amo|ate|aju|am|as|a)\s+'.self::IZMEDJU_POSLE_NEMA.'{0,2}(?:podat|informacij)/',
        // „Nisam našao/la ... podatak", „nismo pronašli ...", „nisam uspeo da nađem ...".
        '/\bnis(?:am|i|mo|te|u)\s+(?:uspe\w+\s+da\s+(?:pro)?nad\w*|(?:pro)?nas(?:ao|la|li|le|lo))\s+'.self::IZMEDJU_POSLE_NEMA.'{0,2}(?:podat|informacij)/',
        // „Podatka nema", „podatka o tome nema".
        '/\b(?:podat|informacij)\w*\s+'.self::IZMEDJU_PRE_NEMA.'{0,3}nem(?:a|am|amo|aju)\b(?!\s+(?:'.self::NEMA_SAVET.'))/',
    ];

    // Odgovor o kalendaru ne sme da kaže šta će biti, samo šta je bilo: „otvoriće se", „se otvara", „biće otvorena", „očekuje se".
    private const PREDVIDJANJE = '/\b(?:(?:se|ce\s+se)\s+otvar\w+|ce\s+se\s+otvori\w*|otvara\s+se|otvori(?:ce|ti)\b|otvorit\s+ce|bice\s+otvoren\w*|ocekuje\s+se|najavljen\w*|sledece\s+godine|naredne\s+godine|ponovo\s+ce)/';

    private const DOMEN = '/(?<![\p{L}\d@.-])(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:rs|com|org|net|eu|info|edu|gov|me|io|co)(?:\/[^\s)\]»"„“]*)?(?![\p{L}\d])/iu';

    /**
     * Vraća razlog odbijanja, ili null kad je odgovor proveren.
     *
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     * @param  Collection<int, Organizacija>  $organizacije
     * @param  Collection<int, KalendarStavka>  $kalendar
     */
    public function razlogOdbijanja(string $odgovor, Collection $zapisi, Collection $vodici, Collection $organizacije = new Collection, Collection $kalendar = new Collection): ?string
    {
        if (trim($odgovor) === '') {
            return 'prazan odgovor';
        }

        if (mb_strlen($odgovor) > self::NAJVISE_ZNAKOVA) {
            return 'predug odgovor';
        }

        // Zapisi postoje, pa odgovor koji kaže da podatka nema protivreči kartici ispod njega.
        if (($zapisi->isNotEmpty() || $vodici->isNotEmpty() || $organizacije->isNotEmpty() || $kalendar->isNotEmpty()) && $this->kazeDaNemaPodatka($odgovor)) {
            return 'odgovor kaže da nema podatka';
        }

        if (! $this->datumiSuIzZapisa($odgovor, $zapisi, $vodici, $organizacije, $kalendar)) {
            return 'datum kog nema u zapisima';
        }

        // Kalendar nikad ne predviđa: budući datum (godina posle današnjeg dana) sme samo ako je rok neke prilike u odgovoru,
        // a rečenica o tome šta će biti otvoreno ne prolazi.
        if ($kalendar->isNotEmpty() && ($this->imaBuduciDatum($odgovor, $zapisi) || $this->predvidja($odgovor))) {
            return 'odgovor predviđa budućnost';
        }

        if (! $this->linkoviSuIzZapisa($odgovor, $zapisi, $vodici, $organizacije, $kalendar)) {
            return 'link kog nema u zapisima';
        }

        return null;
    }

    // Poredi se rečenica po rečenica (i deo posle zareza): normalizacija briše interpunkciju, pa bi se bez ove podele
    // „Nema roka. Informacije su na sajtu." čitalo kao „nema informacije".
    private function kazeDaNemaPodatka(string $odgovor): bool
    {
        foreach (preg_split('/[.,;:!?()\n\r–—]+/u', $odgovor) ?: [] as $deo) {
            $tekst = PoredjenjeTeksta::normalizuj($deo);

            foreach (self::NEMA_PODATKA as $oblik) {
                if (preg_match($oblik, $tekst) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     * @param  Collection<int, Organizacija>  $organizacije
     */
    private function datumiSuIzZapisa(string $odgovor, Collection $zapisi, Collection $vodici, Collection $organizacije, Collection $kalendar): bool
    {
        $dozvoljeni = [];

        foreach ($kalendar as $stavka) {
            foreach (array_filter([$stavka->otvoreno_od, $stavka->otvoreno_do]) as $datum) {
                $dozvoljeni[] = ['dan' => $datum->day, 'mesec' => $datum->month, 'godina' => $datum->year];
            }

            array_push($dozvoljeni, ...DatumiUTekstu::izvuci($stavka->naziv.' '.$stavka->ko_daje.' '.$stavka->napomena));
        }

        foreach ($organizacije as $organizacija) {
            array_push($dozvoljeni, ...DatumiUTekstu::izvuci($organizacija->naziv.' '.$organizacija->kratak_opis.' '.implode(' ', $organizacija->usluge ?? [])));
        }

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
     * @param  Collection<int, Organizacija>  $organizacije
     */
    private function linkoviSuIzZapisa(string $odgovor, Collection $zapisi, Collection $vodici, Collection $organizacije, Collection $kalendar): bool
    {
        $dozvoljeni = [];

        foreach ($kalendar as $stavka) {
            foreach ([route('kalendar.index'), $stavka->link_izvora] as $adresa) {
                if (filled($adresa)) {
                    $dozvoljeni[] = $this->normalizuj((string) $adresa);
                    $dozvoljeni[] = $this->domen((string) $adresa);
                }
            }
        }

        foreach ($organizacije as $organizacija) {
            foreach ([route('organizacije.show', $organizacija->slug), PrikazOrganizacija::linkSajta($organizacija)] as $adresa) {
                if ($adresa !== null) {
                    $dozvoljeni[] = $this->normalizuj($adresa);
                    $dozvoljeni[] = $this->domen($adresa);
                }
            }
        }

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

    // Datum sa godinom posle današnjeg dana je budući; sme samo ako je rok neke prilike u odgovoru (prilike imaju buduće rokove).
    /** @param  Collection<int, Prilika>  $zapisi */
    private function imaBuduciDatum(string $odgovor, Collection $zapisi): bool
    {
        foreach (DatumiUTekstu::izvuci($odgovor) as $datum) {
            if ($datum['godina'] === null || ! checkdate($datum['mesec'], $datum['dan'], $datum['godina'])) {
                continue;
            }

            $buduci = Carbon::create($datum['godina'], $datum['mesec'], $datum['dan'])->startOfDay()->greaterThan(today());
            $jeRokPrilike = $zapisi->contains(fn (Prilika $prilika) => $prilika->rok !== null && $prilika->rok->isSameDay(Carbon::create($datum['godina'], $datum['mesec'], $datum['dan'])));

            if ($buduci && ! $jeRokPrilike) {
                return true;
            }
        }

        return false;
    }

    private function predvidja(string $odgovor): bool
    {
        return preg_match(self::PREDVIDJANJE, PoredjenjeTeksta::normalizuj($odgovor)) === 1;
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
