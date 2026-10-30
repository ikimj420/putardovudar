<?php

namespace App\Services;

use App\Enums\Grupa;
use App\Enums\KoJeObjavio;
use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Services\Model\ModelObrade;
use App\Services\Ollama\OdgovorNijeJson;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\DatumiUTekstu;
use App\Support\DatumUTekstu;
use App\Support\Latinica;
use App\Support\PoredjenjeTeksta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

// Model (Ollama ili Claude, MODEL_OBRADE) predlaže, kod odlučuje: objava samo ako je sve što model tvrdi proverljivo u tekstu strane. Model kaže samo da li je tekst prilika; grupu dokazuje
// kod (citat sa rečju grupe koji piše na strani), a oglas za posao važi i bez grupe.
final class ObradaNacrta
{
    private const UPUTSTVO = <<<'TEXT'
Proveravaš oglas za bazu prilika. Odgovaraj samo iz prosleđenog teksta, ništa ne dodaj iz sopstvenog znanja.
Pravilo: Objavljuje se stvarna prilika (posao, praksa, stipendija, konkurs ili obuka) dostupna u Srbiji bar jednoj od ovih grupa: osnovci, srednjoškolci, studenti, master i doktorski studenti, nezaposleni (bilo kog uzrasta), mladi, povratnici iz inostranstva, organizacije, odrasli bez završene škole — ili ono što je namenjeno Romima. Oglas za posao u Srbiji važi i bez navedene grupe.
Organizacije su udruženja i organizacije civilnog društva, ne firme; ono što je samo za firme nije za nijednu grupu.
Rezultati konkursa, liste dobitnika, vesti i obaveštenja nisu prilika. Ako nije jasno, vazi_pravilo je false.
Vrati samo JSON objekat sa ključevima, tačno ovim redom:
grupe (lista objekata {"grupa": ..., "citat": ...}, po jedan za svaku grupu kojoj je prilika namenjena, ili prazna lista; grupa je jedno od: osnovci, srednjoskolci, studenti, master-doktorski (master ili doktorske studije), nezaposleni-mladi (nezaposleni svih uzrasta, ne samo mladi), mladi (samo kad tekst kaže „mladi“, „mlade“ ili „mladih“; omladinske organizacije nisu mladi), povratnici (iz inostranstva), organizacije (udruženja i organizacije civilnog društva), romi, odrasli-bez-skole (odrasli bez završene osnovne ili srednje škole, nepismeni, bez kvalifikacija); citat je tačan isečak iz teksta koji kaže da je prilika namenjena toj grupi i sadrži reč te grupe),
vazi_pravilo (true ako je tekst prilika na koju se neko prijavljuje (posao, praksa, stipendija, konkurs ili obuka), a ne rezultat, lista ili vest; da li je grupa dokazana ne ocenjuješ ti, to proverava kod; inače false),
razlog (jedna rečenica na srpskom latinicom, zašto pravilo važi ili ne važi),
vrsta (jedno od: posao, praksa, stipendija, konkurs, obuka, drugo; reč te vrste ili njoj srodna mora da piše u tekstu),
rok (poslednji dan za prijavu, oblika YYYY-MM-DD, ili null ako u tekstu ne piše),
citat (tačan isečak iz teksta u kome piše rok, ili prazan tekst).
TEXT;

    // Verzija pravila upisana u predlog; nacrt obrađen starim pravilom je nema, pa ga uvoz:obradi --ponovo prepoznaje.
    public const PRAVILO = 5;

    // Razlog objave oglasa za posao, uvek kodov (admin ga vidi uz priliku); tekst je predlog (PITANJA-63.md), a Ivan ga je prihvatio 11.10.2026.
    public const RAZLOG_POSAO = 'Oglas za posao: vrsta i rok pišu na strani, a naslov nije rezultat ni spisak.';

    // Koliko reči ispred datuma se gleda za reč roka (paket 70).
    private const NAJVISE_RECI_PRE_DATUMA = 12;

    // Najduža rečenica koju kod uzima kao citat roka celu; duža se seče oko datuma.
    private const NAJVISE_ZNAKOVA_CITATA_ROKA = 300;

    // Rok bez godine koji pada dalje od ovoliko meseci posle datuma objave ostaje nacrt (paket 84): „5. decembra" u vesti od 8. decembra 2025. nije 5. decembar 2026.
    private const NAJVISE_MESECI_ROKA_BEZ_GODINE = 6;

    // Razlozi zbog kojih nacrt nema rok koji piše na strani (ostali razlozi su ispred provere roka); samo tada se za Infostud traži datum isteka oglasa.
    private const RAZLOZI_BEZ_ROKA = ['nema ispravnog roka', 'rok ne piše u tekstu strane'];

    public function __construct(private readonly ModelObrade $model, private readonly TekstStrane $strana, private readonly CitatGrupe $citatGrupe, private readonly RokSaInfostuda $rokInfostuda) {}

    /** @throws OllamaNedostupna */
    public function obradi(Prilika $prilika): IshodObrade
    {
        try {
            $preuzeta = $this->strana->preuzmi((string) $prilika->link_izvora, $this->model->najviseZnakova());
            $tekst = $preuzeta->tekst;
        } catch (StranaNedostupna $izuzetak) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, $izuzetak->getMessage());
        }

        try {
            $forma = $this->model->odgovoriJsonom(self::UPUTSTVO, "Naslov: {$prilika->naslov}\n\nTekst strane:\n{$tekst}");
        } catch (OdgovorNijeJson) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, 'odgovor modela nije ispravan JSON');
        }

        // Odgovor sa nevažećim UTF-8 (bajtovi odsečeni usred znaka) ruši proveru citata (preg_match) i upis predloga: stavka ostaje neobrađena, a obrada ide dalje (paket 74).
        if (! $this->jeUtf8($forma)) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, 'odgovor modela nije ispravan');
        }

        // Model ume da pomeša ćirilična slova u latinične reči (vrsta „drugo" sa ćiriličnim „o") ili da citira ćirilicom; strana je već latinica, pa se odgovor pretvara pre svake provere (paket 70).
        $forma = $this->latinicom($forma);

        if (! $this->imaOblik($forma)) {
            return new IshodObrade(IshodObrade::NIJE_OBRADJENO, 'odgovor modela nema tražena polja');
        }

        // Model ponekad izostavi vrstu, rok ili citat (izmereno: stavlja ih u objekat grupe); izostavljeno je isto što i null, a bez njih objave nema.
        $nedostajala = array_values(array_filter(['vrsta', 'rok', 'citat'], fn (string $kljuc) => ! array_key_exists($kljuc, $forma)));
        $forma = [...['vrsta' => null, 'rok' => null, 'citat' => null], ...$forma];
        $rokModela = $forma['rok'];

        $vrstaModela = $forma['vrsta'];
        [$forma, $vrstaIzvor] = $this->vrstaSaStrane($forma, $tekst);
        [$forma, $izvorCitata] = $this->citatRokaSaStrane($forma, $tekst);

        [$prihvacene, $grupe] = $this->prihvaceneGrupe($this->grupeModela($forma), $tekst);
        [$odbijeno, $rok] = $this->zasto($forma, $tekst, $prihvacene, $prilika->izvor_objavljen, (string) $prilika->naslov);

        // Kad model ne nađe rok ili rok koji je vratio nije dokazan citatom sa strane, a strana je sa Infostuda, rok je datum isteka oglasa iz strane (samo ako piše i u tekstu);
        // citat roka je taj datum (paket 67). Rok modela koji je dokazan se ne menja.
        $infostud = null;

        if (in_array($odbijeno, self::RAZLOZI_BEZ_ROKA, true)) {
            $infostud = $this->rokInfostuda->izStrane((string) $prilika->link_izvora, $preuzeta->html, $tekst);

            if ($infostud !== null) {
                $forma['rok'] = $infostud['rok'];
                $forma['citat'] = $infostud['citat'];
                $izvorCitata = 'kod';
                [$odbijeno, $rok] = $this->zasto($forma, $tekst, $prihvacene, $prilika->izvor_objavljen, (string) $prilika->naslov);
            }
        }

        $predlog = [
            'pravilo' => self::PRAVILO,
            'vrsta' => $forma['vrsta'],
            'vrsta_modela' => $vrstaModela,
            'vrsta_izvor' => $vrstaIzvor,
            'rok' => $rok?->toDateString() ?? $forma['rok'],
            'rok_modela' => $rokModela,
            'rok_sa_infostuda' => $infostud !== null,
            'razlog' => $forma['razlog'],
            'citat' => $forma['citat'],
            'izvor_citata' => $izvorCitata,
            'grupe' => $grupe,
            'prihvacene_grupe' => array_map(fn (Grupa $grupa) => $grupa->value, $prihvacene),
            'odbijeno' => $odbijeno,
            'nedostajala_polja' => $nedostajala,
            'model' => $this->model->model(),
        ];

        $prilika->obradeno_at = now();
        $prilika->predlog = $predlog;

        if ($odbijeno === null) {
            $prilika->vrsta = VrstaPrilike::from($forma['vrsta']);
            $prilika->grupe = array_map(fn (Grupa $grupa) => $grupa->value, $prihvacene);
            $prilika->setAttribute('rok', $rok?->toDateString());
            // Za posao se čuva samo kodov razlog (paket 67): mišljenje modela ne računa, a njegov razlog je tvrdio grupu koja nije dokazana (izmereno u probi 63). Ostale vrste čuvaju modelov.
            $prilika->razlog_objave = $prilika->vrsta === VrstaPrilike::Posao ? self::RAZLOG_POSAO : $forma['razlog'];

            try {
                $prilika->objavi(KoJeObjavio::Ollama);

                return new IshodObrade(IshodObrade::OBJAVA, $prilika->razlog_objave);
            } catch (ValidationException) {
                $prilika->refresh();
                $prilika->forceFill(['obradeno_at' => now(), 'predlog' => [...$predlog, 'odbijeno' => 'izvor']])->save();

                return new IshodObrade(IshodObrade::NACRT, 'nema ispravnog linka izvora');
            }
        }

        $prilika->save();

        return new IshodObrade(IshodObrade::NACRT, $odbijeno);
    }

    /** @param  array<string, mixed>  $forma */
    private function imaOblik(array $forma): bool
    {
        return is_bool($forma['vazi_pravilo'] ?? null)
            && is_string($forma['razlog'] ?? null)
            && $this->jeTekstIliNull($forma, 'vrsta')
            && $this->jeTekstIliNull($forma, 'rok')
            && $this->jeTekstIliNull($forma, 'citat');
    }

    // Kad pravilo ne važi, model vraća null za vrstu, rok i citat (izmereno u probi sa pravom Ollamom); ključ može i da izostane, ali ako piše, mora
    // da bude tekst ili null.
    /** @param  array<string, mixed>  $forma */
    private function jeTekstIliNull(array $forma, string $kljuc): bool
    {
        return ! array_key_exists($kljuc, $forma) || is_string($forma[$kljuc]) || $forma[$kljuc] === null;
    }

    // Model vraća grupe sa citatom za svaku; ostaje samo šta ima oblik {grupa, citat} i grupu sa spiska.
    /**
     * @param  array<string, mixed>  $forma
     * @return list<array{grupa: string, citat: string}>
     */
    private function grupeModela(array $forma): array
    {
        $grupe = [];

        foreach (is_array($forma['grupe'] ?? null) ? $forma['grupe'] : [] as $stavka) {
            $grupa = is_array($stavka) && is_string($stavka['grupa'] ?? null) ? Grupa::tryFrom($stavka['grupa']) : null;

            if ($grupa !== null && is_string($stavka['citat'] ?? null)) {
                $grupe[] = ['grupa' => $grupa->value, 'citat' => $stavka['citat']];
            }
        }

        return $grupe;
    }

    // Grupa se prihvata samo kad njen citat doslovno piše na strani i sadrži reč te grupe. Ako citat modela ne piše na strani, ili piše ali nema reč
    // te grupe, kod sam traži na strani rečenicu sa rečju te grupe i nju čuva kao citat (izvor_citata „kod"); bez takve rečenice grupa ne prolazi.
    /**
     * @param  list<array{grupa: string, citat: string}>  $grupe
     * @return array{0: list<Grupa>, 1: list<array{grupa: string, citat: string, izvor_citata?: string}>} prihvaćene grupe i stavke sa citatom koji je važio
     */
    private function prihvaceneGrupe(array $grupe, string $tekst): array
    {
        $strana = TekstStrane::normalizuj($tekst);
        $prihvacene = [];
        $stavke = [];

        foreach ($grupe as $stavka) {
            $grupa = Grupa::from($stavka['grupa']);
            $citat = TekstStrane::normalizuj($stavka['citat']);
            $naStrani = $citat !== '' && $this->pisePoRecima($strana, $citat);

            // Citat modela koji stoji samo u bloku „Povezano" ili podnožju ne dokazuje grupu (paket 67); tada grupu traži kod, i bez rečenice izvan bloka ona ne prolazi.
            if ($naStrani && $grupa->potkrepljuje($citat) && ! $this->citatGrupe->samoUBlokuVeza($stavka['citat'], $tekst)) {
                $prihvacene[] = $grupa;
            } elseif (($nadjen = $this->citatGrupe->naStrani($grupa, $tekst)) !== null && $this->pisePoRecima($strana, TekstStrane::normalizuj($nadjen))) {
                $prihvacene[] = $grupa;
                $stavka = ['grupa' => $stavka['grupa'], 'citat' => $nadjen, 'izvor_citata' => 'kod'];
            }

            $stavke[] = $stavka;
        }

        return [Grupa::izVrednosti(array_map(fn (Grupa $grupa) => $grupa->value, $prihvacene)), $stavke];
    }

    // Model ponekad pogreši vrstu (izmereno: „praksa" za poziv programu, a reč „praksa" ne piše na strani). Kad on kaže da je prilika, a njegova vrsta nije
    // dokazana rečju sa strane, uzima se jedina vrsta koja jeste dokazana; kad ih je više ili nijedna, ostaje njegova, pa nacrt kao do sada (paket 68).
    // „Posao" kod ne dodeljuje: važi i bez grupe, pa bi „zaposlenima" u vebinaru napravilo oglas (paket 72).
    /**
     * @param  array<string, mixed>  $forma
     * @return array{0: array<string, mixed>, 1: string} oblik sa vrstom i ko je vrstu odredio („model" ili „kod")
     */
    private function vrstaSaStrane(array $forma, string $tekst): array
    {
        $vrsta = is_string($forma['vrsta']) ? VrstaPrilike::tryFrom($forma['vrsta']) : null;

        $dokazane = array_values(array_filter(VrstaPrilike::cases(), fn (VrstaPrilike $druga) => $druga->potkrepljenaTekstom($tekst)));

        if ($vrsta === null || $forma['vazi_pravilo'] !== true || count($dokazane) !== 1 || $dokazane[0] === $vrsta || $dokazane[0] === VrstaPrilike::Posao) {
            return [$forma, 'model'];
        }

        return [[...$forma, 'vrsta' => $dokazane[0]->value], 'kod'];
    }

    // Svaki tekst u odgovoru (i ključevi), ma koliko duboko stajao, mora da je važeći UTF-8.
    private function jeUtf8(mixed $vrednost): bool
    {
        if (is_string($vrednost)) {
            return mb_check_encoding($vrednost, 'UTF-8');
        }

        foreach (is_array($vrednost) ? $vrednost : [] as $kljuc => $deo) {
            if (! $this->jeUtf8((string) $kljuc) || ! $this->jeUtf8($deo)) {
                return false;
            }
        }

        return true;
    }

    // Svaki tekst u odgovoru modela, ma koliko duboko stajao (vrsta, rok, razlog, citati, grupe), kroz Latinica::izCirilice.
    private function latinicom(mixed $vrednost): mixed
    {
        return match (true) {
            is_string($vrednost) => Latinica::izCirilice($vrednost),
            is_array($vrednost) => array_map($this->latinicom(...), $vrednost),
            default => $vrednost,
        };
    }

    // Rok je datum samo ako je tačno oblika YYYY-MM-DD i dan postoji; šta god drugo model vrati („15. novembar", „sutra", broj), nije datum (paket 70).
    private function datum(mixed $vrednost): ?CarbonImmutable
    {
        if (! is_string($vrednost) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $vrednost, $deo) !== 1 || ! checkdate((int) $deo[2], (int) $deo[3], (int) $deo[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $deo[1], (int) $deo[2], (int) $deo[3])->startOfDay();
    }

    // Citat roka koji je model vratio mora da stoji na strani. Kad ne stoji (model pogreši slovo), kod sam traži rečenicu sa istim datumom i rečju roka i nju uzima
    // kao citat; datum samo uz događaj ne prolazi. Bez takve rečenice sve ostaje kako je bilo (paket 68).
    /**
     * @param  array<string, mixed>  $forma
     * @return array{0: array<string, mixed>, 1: string} oblik sa citatom i ko je citat odredio („model" ili „kod")
     */
    private function citatRokaSaStrane(array $forma, string $tekst): array
    {
        $rok = $this->datum($forma['rok']);

        if ($rok === null) {
            return [$forma, 'model'];
        }

        $citat = TekstStrane::normalizuj((string) $forma['citat']);

        // Bez citata objave nema (paket 59): kod ne traži rečenicu umesto citata koga model nije ni naveo.
        if ($citat === '' || $this->pisePoRecima(TekstStrane::normalizuj($tekst), $citat)) {
            return [$forma, 'model'];
        }

        $nadjena = $this->recenicaSaRokom($rok, $tekst);

        return $nadjena === null ? [$forma, 'model'] : [[...$forma, 'citat' => $nadjena], 'kod'];
    }

    // Reč roka mora da stoji najviše 12 reči ispred datuma, i posle prethodnog datuma u rečenici (osim kad ih spaja samo „do" ili crtica, kao u „od 28. septembra do 12.
    // oktobra"): „Rok za prijavu je 11. oktobra, a radionica je 17. oktobra." dokazuje samo 11. oktobar (paket 70). Prva rečenica sa „rok…" ima prednost; inače prva sa „prijav…",
    // ili sa „do" uz „otvoren…", „konkurs…" ili „poziv…". Delovi se dele samo pre velikog slova, da „28. septembra" i „12. 10. 2026." ostanu u jednoj rečenici.
    private function recenicaSaRokom(CarbonImmutable $rok, string $tekst): ?string
    {
        $prva = null;

        foreach (preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', $tekst) ?: [] as $deo) {
            $deo = trim($deo);
            $mala = mb_strtolower($deo);
            $pojave = DatumiUTekstu::pojave($deo);

            foreach ($pojave as $i => $pojava) {
                if ($pojava['dan'] !== $rok->day || $pojava['mesec'] !== $rok->month || ($pojava['godina'] !== null && $pojava['godina'] !== $rok->year)) {
                    continue;
                }

                $pocetak = 0;

                for ($j = $i - 1; $j >= 0; $j--) {
                    $izmedju = substr($mala, $pojave[$j]['do'], $pojave[$j + 1]['od'] - $pojave[$j]['do']);

                    if (preg_match('/^[\s.,]*(?:do|-|–|—)[\s.,]*$/u', $izmedju) !== 1) {
                        $pocetak = $pojave[$j]['do'];

                        break;
                    }
                }

                $reci = preg_split('/\s+/u', trim(substr($mala, $pocetak, $pojava['od'] - $pocetak)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $prozor = PoredjenjeTeksta::normalizuj(implode(' ', array_slice($reci, -self::NAJVISE_RECI_PRE_DATUMA)));

                if (preg_match('/\brok\w*/', $prozor) === 1) {
                    return $this->okoDatuma($deo, $rok);
                }

                if ($prva === null && (preg_match('/\bprijav\w*/', $prozor) === 1 || (preg_match('/\bdo\b/', $prozor) === 1 && preg_match('/\b(?:otvoren|konkurs|poziv)\w*/', $prozor) === 1))) {
                    $prva = $deo;
                }
            }
        }

        return $prva === null ? null : $this->okoDatuma($prva, $rok);
    }

    // Duga rečenica se seče oko datuma na cele reči (najviše 12 pre i 14 posle), da citat ostane kratak i doslovan.
    private function okoDatuma(string $recenica, CarbonImmutable $rok): string
    {
        $polozaj = mb_strlen($recenica) > self::NAJVISE_ZNAKOVA_CITATA_ROKA ? DatumUTekstu::prviPolozaj($recenica, $rok->day, $rok->month) : null;

        if ($polozaj === null) {
            return $recenica;
        }

        $pre = preg_split('/\s+/u', trim(substr($recenica, 0, $polozaj))) ?: [];
        $posle = preg_split('/\s+/u', trim(substr($recenica, $polozaj))) ?: [];

        return implode(' ', [...array_slice($pre, -12), ...array_slice($posle, 0, 14)]);
    }

    // Citat piše na strani samo kao cele reči: „roma" nije na strani na kojoj piše samo „Romania".
    private function pisePoRecima(string $strana, string $citat): bool
    {
        return preg_match('/(?<![\p{L}\d])'.preg_quote($citat, '/').'(?![\p{L}\d])/u', $strana) === 1;
    }

    /**
     * Vraća razlog zbog kog nacrt ostaje nacrt (ili null kad je sve proverivo) i rok koji važi: datum iz odgovora, ili, kad na strani piše dan i
     * mesec bez godine, prvi takav dan posle datuma objave u izvoru, najviše šest meseci posle njega. Godinu ne bira model. Za vrstu „posao" mišljenje modela (vazi_pravilo) ne računa.
     *
     * @param  array<string, mixed>  $forma
     * @param  list<Grupa>  $prihvacene
     * @return array{0: ?string, 1: ?CarbonImmutable}
     */
    private function zasto(array $forma, string $tekst, array $prihvacene, ?CarbonInterface $objavljeno, string $naslov): array
    {
        $strana = TekstStrane::normalizuj($tekst);
        $vrsta = is_string($forma['vrsta']) ? VrstaPrilike::tryFrom($forma['vrsta']) : null;

        if ($forma['vazi_pravilo'] !== true && $vrsta !== VrstaPrilike::Posao) {
            return ['pravilo za objavu ne važi', null];
        }

        if ($vrsta === null) {
            return ['vrsta nije sa spiska', null];
        }

        // Vrsta se dokazuje rečju sa strane (ne samo citatom): „stipendija" bez ijedne reči o stipendiji ostaje nacrt.
        if (! $vrsta->potkrepljenaTekstom($tekst)) {
            return ['vrsta nije potkrepljena', null];
        }

        // Oglas za posao: naslov koji kaže da je rezultat ili spisak nije oglas.
        if ($vrsta === VrstaPrilike::Posao && NaslovOglasa::jeRezultatIliSpisak($naslov)) {
            return ['naslov je rezultat ili spisak', null];
        }

        // Grupu dokazuje kod; samo oglas za posao (vrsta dokazana rečju sa strane) važi i bez grupe.
        if ($prihvacene === [] && $vrsta !== VrstaPrilike::Posao) {
            return ['pravilo nije potkrepljeno citatom', null];
        }

        $rok = $this->datum($forma['rok']);

        if ($rok === null) {
            return ['nema ispravnog roka', null];
        }

        $citat = TekstStrane::normalizuj((string) $forma['citat']);

        if (! $this->pisePoRecima($strana, $citat)) {
            return ['rok ne piše u tekstu strane', null];
        }

        if (! DatumUTekstu::postoji($citat, $forma['rok'])) {
            $rok = $this->rokBezGodine($rok, $citat, $objavljeno);

            if ($rok === null) {
                return ['rok ne piše u tekstu strane', null];
            }

            if ($objavljeno !== null && $rok->greaterThan(CarbonImmutable::parse($objavljeno->toDateString())->addMonthsNoOverflow(self::NAJVISE_MESECI_ROKA_BEZ_GODINE))) {
                return ['rok bez godine je daleko od datuma objave', null];
            }
        }

        return [$rok->lessThan(today()) ? 'rok je prošao' : null, $rok];
    }

    // Dan i mesec iz odgovora moraju da pišu u citatu bez godine; godina je ona u kojoj je taj dan prvi put posle datuma objave. Bez datuma
    // objave, ili kad u citatu piše druga godina, rok se ne prima.
    private function rokBezGodine(CarbonImmutable $rok, string $citat, ?CarbonInterface $objavljeno): ?CarbonImmutable
    {
        if ($objavljeno === null) {
            return null;
        }

        foreach (DatumiUTekstu::izvuci($citat) as $datum) {
            if ($datum['godina'] === null && $datum['dan'] === $rok->day && $datum['mesec'] === $rok->month) {
                return DatumUTekstu::prviPosle($rok->day, $rok->month, $objavljeno);
            }
        }

        return null;
    }
}
