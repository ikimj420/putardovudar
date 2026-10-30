<?php

namespace App\Services;

use App\Models\Prilika;
use App\Support\DatumiUTekstu;
use App\Support\DatumUTekstu;
use App\Support\PoredjenjeTeksta;
use Carbon\CarbonImmutable;

// Dnevna provera javne prilike prema strani izvora (paket 85, Ivan 30.10.2026.: „zašto ja, zašto ne AI"). Samo kod i dokaz sa strane, bez modela: dva prolaza nad istom stranom
// moraju dati isti ishod. Prilika se vraća u nacrt ili dobija nov rok samo uz rečenicu sa strane; sve ostalo ostaje kako jeste.
final class ProveraPrilike
{
    public const RAZLOG_ZATVOREN = 'strana piše da je poziv zatvoren';

    public const RAZLOG_BEZ_STRANE = 'strana izvora ne postoji dva dana zaredom';

    private const NAJVISE_ZNAKOVA_CITATA = 300;

    // Predmet (poziv, konkurs, oglas, prijave, rok) pa u najviše tri reči „je/su zatvoren/završen/istekao…"; reči između počinju malim slovom, da naslov zalepljen uz rečenicu
    // („Poziv Radionica je završena") nije predmet. Jednina („prijava je završena slanjem") i „nije zatvoren" ne hvataju. Lookahead: „Poziv Konkurs je zatvoren" nađe i „Konkurs".
    // Tekst je već latinicom (TekstStrane), pa slova sa kvačicom i bez njih važe jednako.
    private const ZATVOREN = '/(?<![\p{L}\d])(?=(?:konkurs|poziv|oglas|prijave|rok)((?: [\p{L}\d]+){0,3}) (?:je|su) (?:zatvoren\p{L}*|zavr[sš]en\p{L}*|istekao|istekla|istekle|pro[sš]ao)(?![\p{L}\d]))/iu';

    public function __construct(private readonly TekstStrane $strana, private readonly RokSaInfostuda $rokInfostuda) {}

    public function proveri(Prilika $prilika): IshodProvere
    {
        // Rok bez godine koji je kod izveo iz datuma objave, a pada dalje od šest meseci posle nje (paket 84): ne treba strana, dovoljan je zapis obrade.
        if ($this->rokBezGodineJeDalek($prilika)) {
            return new IshodProvere(IshodProvere::U_NACRT, RokBezGodine::RAZLOG, (string) data_get($prilika->predlog, 'citat'), stranaStigla: false);
        }

        $adresa = (string) $prilika->link_izvora;

        try {
            $preuzeta = $this->strana->preuzmi($adresa, (int) config('uvoz.najvise_bajtova'));
        } catch (StranaNedostupna $izuzetak) {
            return $this->nedostupna($prilika, $izuzetak);
        }

        return RokSaInfostuda::jeInfostud($adresa) ? $this->infostud($prilika, $preuzeta) : $this->izTeksta($prilika, $preuzeta->tekst);
    }

    private function rokBezGodineJeDalek(Prilika $prilika): bool
    {
        $citat = data_get($prilika->predlog, 'citat');

        if ($prilika->rok_stalno_otvoren || $prilika->rok === null || $prilika->izvor_objavljen === null || ! is_string($citat) || $citat === '') {
            return false;
        }

        // Rok koji je Ivan posle objave promenio više ne odgovara citatu iz obrade, pa se po njemu ne sudi.
        return data_get($prilika->predlog, 'rok') === $prilika->rok->toDateString()
            && ! DatumUTekstu::postoji($citat, $prilika->rok->toDateString())
            && RokBezGodine::jeDalekoOdObjave($prilika->rok, $prilika->izvor_objavljen);
    }

    // 404 i 410 su „strane nema"; drugo (veza, 5xx, 403, prevelik odgovor, prazna strana) nije dokaz ni za šta.
    private function nedostupna(Prilika $prilika, StranaNedostupna $izuzetak): IshodProvere
    {
        if (! in_array($izuzetak->status, [404, 410], true)) {
            return new IshodProvere(IshodProvere::BEZ_PROVERE, $izuzetak->getMessage(), stranaStigla: false);
        }

        $prviPut = $prilika->nedostupnaOd();

        if ($prviPut !== null && $prviPut->lessThan(today())) {
            return new IshodProvere(IshodProvere::U_NACRT, self::RAZLOG_BEZ_STRANE, $izuzetak->getMessage(), stranaStigla: false);
        }

        return new IshodProvere(IshodProvere::NEDOSTUPNA, $izuzetak->getMessage(), stranaStigla: false);
    }

    // Infostud: samo rok (polje isteka oglasa, koje piše i u tekstu) i „strane nema"; „zatvoren" se ne traži, jer oglas to ne piše rečima.
    private function infostud(Prilika $prilika, PreuzetaStrana $preuzeta): IshodProvere
    {
        if ($prilika->rok_stalno_otvoren) {
            return new IshodProvere(IshodProvere::U_REDU);
        }

        $strana = $this->rokInfostuda->izStrane((string) $prilika->link_izvora, $preuzeta->html, $preuzeta->tekst);

        if ($strana === null) {
            return new IshodProvere(IshodProvere::BEZ_PROVERE, 'strana nema ispravno polje roka');
        }

        $nov = CarbonImmutable::parse($strana['rok'])->startOfDay();

        return $this->odlukaORoku($prilika, [['rok' => $nov, 'citat' => $strana['citat']]], $prilika->rok !== null && $prilika->rok->toDateString() === $nov->toDateString(), false);
    }

    private function izTeksta(Prilika $prilika, string $tekst): IshodProvere
    {
        $zatvoren = $this->zatvoren($tekst);

        if ($zatvoren !== null) {
            return new IshodProvere(IshodProvere::U_NACRT, self::RAZLOG_ZATVOREN, $zatvoren);
        }

        if ($prilika->rok_stalno_otvoren) {
            return new IshodProvere(IshodProvere::U_REDU);
        }

        $rok = $prilika->rok === null ? null : CarbonImmutable::parse($prilika->rok->toDateString())->startOfDay();

        return $this->odlukaORoku($prilika, $this->kasnijiRokovi($rok ?? CarbonImmutable::parse(today()->toDateString())->subDay(), $tekst), $rok !== null && $this->rokNaStrani($rok, $tekst), true);
    }

    /**
     * Rok iz baze piše na strani: u redu. Piše, ali strana kaže „produžen" uz jedan kasniji rok: nov rok. Ne piše, a strana sama piše tačno jedan kasniji rok: nov rok.
     * Ne piše i nov rok se ne može dokazati: nacrt. Prilika bez roka (a nije stalno otvorena) prolazi samo kroz nov rok.
     *
     * @param  list<array{rok: CarbonImmutable, citat: string}>  $kasniji  rokovi koje strana piše uz reč roka, kasniji od onog iz baze
     */
    private function odlukaORoku(Prilika $prilika, array $kasniji, bool $rokNaStrani, bool $trazitiProduzen): IshodProvere
    {
        $jedan = count($kasniji) === 1 ? $kasniji[0] : null;

        if ($jedan !== null && $prilika->rok !== null && $jedan['rok']->lessThanOrEqualTo($prilika->rok)) {
            $jedan = null;
        }

        if ($jedan !== null && (! $rokNaStrani || ($trazitiProduzen && str_contains(PoredjenjeTeksta::normalizuj($jedan['citat']), 'produz')))) {
            return new IshodProvere(IshodProvere::NOV_ROK, citat: $jedan['citat'], rok: $jedan['rok']);
        }

        return $rokNaStrani ? new IshodProvere(IshodProvere::U_REDU) : new IshodProvere(IshodProvere::U_NACRT, ObradaNacrta::RAZLOG_ROK_NE_PISE);
    }

    // Dan i mesec iz baze pišu na strani (godina, ako piše, mora da je ista).
    private function rokNaStrani(CarbonImmutable $rok, string $tekst): bool
    {
        foreach (DatumiUTekstu::pojave($tekst) as $pojava) {
            if ($pojava['dan'] === $rok->day && $pojava['mesec'] === $rok->month && ($pojava['godina'] === null || $pojava['godina'] === $rok->year)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Datumi sa godinom, posle $posle, uz čiju rečenicu stoji reč roka (isti dokaz kao pri obradi). Datum bez godine se ne uzima: godinu ne bira kod.
     *
     * @return list<array{rok: CarbonImmutable, citat: string}>
     */
    private function kasnijiRokovi(CarbonImmutable $posle, string $tekst): array
    {
        $nadjeni = [];

        foreach (DatumiUTekstu::izvuci($tekst) as $datum) {
            if ($datum['godina'] === null || ! checkdate($datum['mesec'], $datum['dan'], $datum['godina'])) {
                continue;
            }

            $rok = CarbonImmutable::create($datum['godina'], $datum['mesec'], $datum['dan'])->startOfDay();
            $citat = $rok->greaterThan($posle) ? CitatRoka::recenicaSaRokom($rok, $tekst) : null;

            if ($citat !== null) {
                $nadjeni[] = ['rok' => $rok, 'citat' => $citat];
            }
        }

        return $nadjeni;
    }

    // Rečenica u kojoj piše da je poziv zatvoren; prva takva je dokaz.
    private function zatvoren(string $tekst): ?string
    {
        foreach (preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', $tekst) ?: [] as $recenica) {
            if (preg_match_all(self::ZATVOREN, $recenica, $nalazi) > 0 && array_filter($nalazi[1], fn (string $izmedju) => preg_match('/^(?: [\p{Ll}\d]\S*)*$/u', $izmedju) === 1) !== []) {
                return mb_strimwidth(trim($recenica), 0, self::NAJVISE_ZNAKOVA_CITATA, '…');
            }
        }

        return null;
    }
}
