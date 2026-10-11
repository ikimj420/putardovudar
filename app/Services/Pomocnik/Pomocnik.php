<?php

namespace App\Services\Pomocnik;

use App\Enums\VrstaPrilike;
use App\Models\KalendarStavka;
use App\Models\Organizacija;
use App\Models\Prilika;
use App\Models\Vodic;
use App\Services\Ollama\OllamaNedostupna;
use App\Support\Latinica;
use App\Support\LinkSajta;
use App\Support\PrikazKalendara;
use App\Support\PrikazOrganizacija;
use App\Support\PrikazPrilike;
use App\Support\ZapisGreske;
use Illuminate\Support\Collection;
use Throwable;

// Pomoćnik odgovara samo iz baze: model pretvara pitanje u formular, a pretragu i proveru radi kod.
final class Pomocnik
{
    public const NEMAM_PODATAK = 'Za ovo trenutno nemam ništa na sajtu. Probaj da pitaš drugim rečima ili pogledaj Vodiče.';

    // Predlog teksta; Ivan odobrava (PITANJA-23-25.md).
    public const NE_MOGU_DA_POMOGNEM = 'U tome ti ne mogu pomoći. Ako ti treba pomoć uživo, pogledaj Organizacije.';

    // Jedan naslov za sve vrste kartica (paket 67; ranije po jedan za prilike, vodiče, organizacije i kalendar).
    public const SABLON_NASLOV = 'Evo šta sam našao za tebe:';

    // Predlog tekstova za „napiši mi ..." (paket 56); Ivan odobrava (PITANJA-55-56.md).
    public const PONUDA_SABLONA = 'Za to imam šablon. Odgovori na nekoliko pitanja, a ja pišem tekst.';

    public const NEMA_SABLONA = 'Za to još nema šablona.';

    // Pitanje samo sa vrstom („stipendije"), a otvorenih prilika te vrste nema (paket 65/T3). Tekst za stipendije je Coworkov; ostale vrste idu istim obrascem,
    // sa množinom u genitivu (PITANJA-65.md).
    public const NEMA_OTVORENIH = 'Trenutno nema otvorenih %s.';

    public const DOK_CEKAS = 'Dok čekaš, ovo ti može pomoći da budeš spreman:';

    public const VRSTE_U_GENITIVU = ['posao' => 'poslova', 'praksa' => 'praksi', 'stipendija' => 'stipendija', 'konkurs' => 'konkursa', 'obuka' => 'obuka'];

    public const NAJVISE_ZNAKOVA_PITANJA = 300;

    public function __construct(
        private readonly PitanjeUFormular $formular,
        private readonly PretragaPrilika $pretraga,
        private readonly PretragaVodica $pretragaVodica,
        private readonly PretragaOrganizacija $pretragaOrganizacija,
        private readonly PretragaKalendara $pretragaKalendara,
        private readonly PisanjeOdgovora $pisanje,
        private readonly ProveraOdgovora $provera,
        private readonly OpasnaPitanja $opasnaPitanja,
        private readonly PrepoznavanjeSablona $sabloni,
    ) {}

    /** @throws OllamaNedostupna */
    public function pretrazi(string $pitanje, ?int $vremeCekanja = null): RezultatPretrage
    {
        $pitanje = $this->ocisti($pitanje);

        // Prazno pitanje se ne šalje modelu: nema šta da se pita. Opasno pitanje se ne šalje ni modelu ni pretrazi.
        if ($pitanje === '' || $this->opasnaPitanja->jeOpasno($pitanje)) {
            return new RezultatPretrage(new Formular, new Collection, new Collection, new Collection, new Collection);
        }

        $formular = $this->formular->izPitanja($pitanje, $vremeCekanja);

        return new RezultatPretrage($formular, $this->pretraga->pronadji($formular), $this->pretragaVodica->pronadji($formular), $this->pretragaOrganizacija->pronadji($formular), $this->pretragaKalendara->pronadji($formular));
    }

    /**
     * Pitanje → formular → pretraga → odgovor. Nema zapisa: „nemam podatak" i model se ne zove drugi put.
     * Ako model u odgovoru ne prođe proveru (ili ne radi, ili nema dovoljno vremena), umesto njegovog odgovora stoji šablon iz zapisa.
     * Oba poziva zajedno staju u `pomocnik.ukupno_za_pitanje`: prvi dobija polovinu, drugi ostatak.
     *
     * @throws OllamaNedostupna samo kad ne radi prvi poziv (formular); tada nema šta da se kaže
     */
    public function odgovori(string $pitanje): OdgovorPomocnika
    {
        // Provera i oba poziva modela vide isti tekst; ćirilica se pretvara u duži latinični tekst, pa se sirovo pitanje ne šalje.
        $pitanje = $this->ocisti($pitanje);

        // Opasno pitanje dobija jednu rečenicu: bez modela, bez pretrage, bez izvora.
        if ($this->opasnaPitanja->jeOpasno($pitanje)) {
            return new OdgovorPomocnika(self::NE_MOGU_DA_POMOGNEM, [], false, null, true);
        }

        // Traženje da se nešto napiše sa nazivom objavljenog šablona: nudi se šablon, bez poziva modela.
        $pisanje = $this->sabloni->jeTrazenjePisanja($pitanje);
        $ponude = $pisanje ? $this->sabloni->ponude($pitanje) : [];

        if ($ponude !== []) {
            return new OdgovorPomocnika(self::PONUDA_SABLONA, [], false, null, false, $ponude);
        }

        $ukupno = (int) config('pomocnik.ukupno_za_pitanje');
        $pocetak = now();
        $rezultat = $this->pretrazi($pitanje, intdiv($ukupno, 2));

        // Samo vrsta i nijedna otvorena prilika te vrste: model se ne zove drugi put, a uz rečenicu idu vodiči i kalendar koji tu vrstu pominju.
        if (! $pisanje && $rezultat->zapisi->isEmpty() && $this->jeSamoVrsta($rezultat->formular)) {
            return $this->bezOtvorenih($rezultat->formular->vrsta);
        }

        if ($rezultat->nemaPodatak()) {
            // Traženo je da se nešto napiše, a šablona nema: to kaže, uz objavljene vodiče.
            return $pisanje
                ? new OdgovorPomocnika(self::NEMA_SABLONA, Vodic::javni()->orderBy('naslov')->limit(PretragaVodica::NAJVISE_U_SPISKU)->get()->map(fn (Vodic $vodic) => $this->izvorVodica($vodic))->all(), true)
                : new OdgovorPomocnika(self::NEMAM_PODATAK, [], true);
        }

        $izvori = [
            ...$rezultat->zapisi->map(fn (Prilika $prilika) => $this->izvor($prilika))->all(),
            ...$rezultat->vodici->map(fn (Vodic $vodic) => $this->izvorVodica($vodic))->all(),
            ...$rezultat->organizacije->map(fn (Organizacija $organizacija) => $this->izvorOrganizacije($organizacija))->all(),
            ...$rezultat->kalendar->map(fn (KalendarStavka $stavka) => $this->izvorKalendara($stavka))->all(),
        ];

        $ostalo = $ukupno - (int) ceil($pocetak->diffInSeconds(now()));

        if ($ostalo < (int) config('pomocnik.najmanje_za_odgovor')) {
            return new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, 'nema vremena');
        }

        try {
            $tekst = $this->pisanje->napisi($pitanje, $rezultat->zapisi, $rezultat->vodici, $rezultat->organizacije, $rezultat->kalendar, $ostalo);
        } catch (OllamaNedostupna) {
            return new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, 'model ne odgovara');
        } catch (Throwable $izuzetak) {
            // Kao kad model ne radi, ali sa zapisom samo vrste greške: poruka i trag poziva mogu da nose pitanje i odgovor (paket 64).
            ZapisGreske::vrsta('Pomoćnik', $izuzetak);

            return new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, 'neočekivana greška');
        }

        $razlog = $this->provera->razlogOdbijanja($tekst, $rezultat->zapisi, $rezultat->vodici, $rezultat->organizacije, $rezultat->kalendar);

        return $razlog === null
            ? new OdgovorPomocnika($tekst, $izvori, false)
            : new OdgovorPomocnika($this->sablon($rezultat), $izvori, false, $razlog);
    }

    // Pitanje je samo vrsta (posao, stipendija...) izrečena u pitanju: bez grada, „kome", reči i grupe. „Drugo" nema reč, pa ga nema ni ovde.
    private function jeSamoVrsta(Formular $formular): bool
    {
        return $formular->vrsta !== null && $formular->vrsta !== VrstaPrilike::Drugo && $formular->vrstaJePomenuta
            && $formular->grad === null && $formular->kome === null && $formular->kljucneReci === [] && $formular->grupe === [];
    }

    // Vodiči i stavke kalendara su oni čiji tekst pominje tu vrstu (ista reč kojom se vrsta dokazuje na strani); samo ono što piše u bazi.
    private function bezOtvorenih(VrstaPrilike $vrsta): OdgovorPomocnika
    {
        $nema = sprintf(self::NEMA_OTVORENIH, self::VRSTE_U_GENITIVU[$vrsta->value]);
        $vodici = Vodic::javni()->orderBy('naslov')->get()->filter(fn (Vodic $vodic) => $vrsta->potkrepljenaTekstom($vodic->naslov))->take(PretragaVodica::NAJVISE_U_SPISKU);
        $kalendar = KalendarStavka::javne()->orderBy('otvoreno_do', 'desc')->orderBy('id')->get()
            ->filter(fn (KalendarStavka $stavka) => $vrsta->potkrepljenaTekstom($stavka->naziv.' '.$stavka->ko_daje.' '.$stavka->napomena))->take(PretragaKalendara::NAJVISE_ZAPISA);
        $izvori = [...$vodici->map(fn (Vodic $vodic) => $this->izvorVodica($vodic))->all(), ...$kalendar->map(fn (KalendarStavka $stavka) => $this->izvorKalendara($stavka))->all()];

        // Bez ičega što bi pomoglo, rečenica o pripremi bi obećala karticu kojih nema.
        return $izvori === [] ? new OdgovorPomocnika($nema, [], true) : new OdgovorPomocnika($nema.' '.self::DOK_CEKAS, $izvori, false, 'nema otvorenih prilika te vrste');
    }

    // Isto što ide modelu: latinica, bez razmaka na krajevima, najviše 300 znakova.
    private function ocisti(string $pitanje): string
    {
        return mb_substr(trim(Latinica::izCirilice($pitanje)), 0, self::NAJVISE_ZNAKOVA_PITANJA);
    }

    // Naslov stoji jednom na vrhu, a ispod su sve stavke redom: prilike, vodiči, organizacije, kalendar (paket 67); ranije se isti red ponavljao iznad svakog bloka.
    private function sablon(RezultatPretrage $rezultat): string
    {
        $stavke = [
            ...$rezultat->zapisi->map(fn (Prilika $prilika) => '• '.$prilika->naslov.' — '.PrikazPrilike::rok($prilika))->all(),
            ...$rezultat->vodici->map(fn (Vodic $vodic) => '• '.$vodic->naslov)->all(),
            ...$rezultat->organizacije->map(fn (Organizacija $organizacija) => '• '.$organizacija->naziv)->all(),
            ...$rezultat->kalendar->map(fn (KalendarStavka $stavka) => '• '.$stavka->naziv.' — '.PrikazKalendara::prosliPut($stavka))->all(),
        ];

        return $stavke === [] ? '' : self::SABLON_NASLOV."\n".implode("\n", $stavke);
    }

    // Izvor iz kalendara vodi na stranu kalendara, a zvanični izvor je link stavke; umesto roka piše „Prošli put: …".
    private function izvorKalendara(KalendarStavka $stavka): Izvor
    {
        $link = LinkSajta::jeIspravan($stavka->link_izvora) ? trim((string) $stavka->link_izvora) : null;

        return new Izvor($stavka->naziv, PrikazKalendara::prosliPut($stavka), route('kalendar.index'), $link === null ? null : PrikazOrganizacija::nazivSajta($link), $link, false, false, true);
    }

    private function izvorVodica(Vodic $vodic): Izvor
    {
        return new Izvor($vodic->naslov, null, route('vodici.show', $vodic->slug), null, null, true);
    }

    // Sajt organizacije je njen zvanični izvor; adresa koja nije veb adresa se ne prikazuje.
    private function izvorOrganizacije(Organizacija $organizacija): Izvor
    {
        $sajt = PrikazOrganizacija::linkSajta($organizacija);

        return new Izvor($organizacija->naziv, null, route('organizacije.show', $organizacija->slug), $sajt === null ? null : PrikazOrganizacija::nazivSajta($sajt), $sajt, false, true);
    }

    private function izvor(Prilika $prilika): Izvor
    {
        return new Izvor(
            $prilika->naslov,
            PrikazPrilike::rok($prilika),
            route('prilike.show', $prilika->slug),
            PrikazPrilike::nazivIzvora($prilika),
            PrikazPrilike::linkIzvora($prilika),
        );
    }
}
