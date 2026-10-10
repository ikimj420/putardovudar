<?php

namespace App\Services\Pomocnik;

use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Support\DatumiUTekstu;
use App\Support\PoredjenjeTeksta;
use App\Support\PrikazOrganizacija;
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

    private const DOMEN = '/(?<![\p{L}\d@.-])(?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:rs|com|org|net|eu|info|edu|gov|me|io|co)(?:\/[^\s)\]»"„“]*)?(?![\p{L}\d])/iu';

    /**
     * Vraća razlog odbijanja, ili null kad je odgovor proveren.
     *
     * @param  Collection<int, Prilika>  $zapisi
     * @param  Collection<int, Vodic>  $vodici
     * @param  Collection<int, Organizacija>  $organizacije
     */
    public function razlogOdbijanja(string $odgovor, Collection $zapisi, Collection $vodici, Collection $organizacije = new Collection): ?string
    {
        if (trim($odgovor) === '') {
            return 'prazan odgovor';
        }

        if (mb_strlen($odgovor) > self::NAJVISE_ZNAKOVA) {
            return 'predug odgovor';
        }

        // Zapisi postoje, pa odgovor koji kaže da podatka nema protivreči kartici ispod njega.
        if (($zapisi->isNotEmpty() || $vodici->isNotEmpty() || $organizacije->isNotEmpty()) && $this->kazeDaNemaPodatka($odgovor)) {
            return 'odgovor kaže da nema podatka';
        }

        if (! $this->datumiSuIzZapisa($odgovor, $zapisi, $vodici, $organizacije)) {
            return 'datum kog nema u zapisima';
        }

        if (! $this->linkoviSuIzZapisa($odgovor, $zapisi, $vodici, $organizacije)) {
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
    private function datumiSuIzZapisa(string $odgovor, Collection $zapisi, Collection $vodici, Collection $organizacije): bool
    {
        $dozvoljeni = [];

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
    private function linkoviSuIzZapisa(string $odgovor, Collection $zapisi, Collection $vodici, Collection $organizacije): bool
    {
        $dozvoljeni = [];

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
