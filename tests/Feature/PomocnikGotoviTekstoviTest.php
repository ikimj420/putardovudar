<?php

namespace Tests\Feature;

use App\Models\KalendarStavka;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\ProveraOdgovora;
use App\Support\PrikazPomocnika;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

// Paket 65/T1: gotovi tekstovi pomoćnika su ljudskiji (Ivan odobrio smer 10.10.2026., tekstovi su Coworkovi iz ZA-KASNIJE-POMOCNIK.md), a ne obećavaju:
// provera odgovora modela ne pušta buduće vreme, pa ni gotov tekst ne sme da ga ima.
class PomocnikGotoviTekstoviTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> naziv, tekst iz dokumenta */
    public static function tekstovi(): array
    {
        return [
            'uvod' => [PrikazPomocnika::UVOD, 'Ćao! Pitaj me za posao, stipendiju, konkurs ili gde da nađeš pomoć. Kažem ti samo ono što piše na sajtu.'],
            // Isti naslov za prilike, vodiče, organizacije i kalendar, jednom na vrhu (paket 67).
            'kad nađe' => [Pomocnik::SABLON_NASLOV, 'Evo šta sam našao za tebe:'],
            'kad nema ništa' => [Pomocnik::NEMAM_PODATAK, 'Za ovo trenutno nemam ništa na sajtu. Probaj da pitaš drugim rečima ili pogledaj Vodiče.'],
            'kad ne sme' => [Pomocnik::NE_MOGU_DA_POMOGNEM, 'U tome ti ne mogu pomoći. Ako ti treba pomoć uživo, pogledaj Organizacije.'],
            'kad ne radi' => [PrikazPomocnika::NE_RADI, 'Izvini, trenutno ne radim. Probaj malo kasnije.'],
            // Paket 65/T3: pitanje samo sa vrstom, bez otvorenih prilika.
            'nema otvorenih stipendija, uz pripremu' => [sprintf(Pomocnik::NEMA_OTVORENIH, 'stipendija').' '.Pomocnik::DOK_CEKAS, 'Trenutno nema otvorenih stipendija. Dok čekaš, ovo ti može pomoći da se pripremiš:'],
            'nema otvorenih poslova' => [sprintf(Pomocnik::NEMA_OTVORENIH, Pomocnik::VRSTE_U_GENITIVU['posao']), 'Trenutno nema otvorenih poslova.'],
            'nema otvorenih praksi' => [sprintf(Pomocnik::NEMA_OTVORENIH, Pomocnik::VRSTE_U_GENITIVU['praksa']), 'Trenutno nema otvorenih praksi.'],
            'nema otvorenih konkursa' => [sprintf(Pomocnik::NEMA_OTVORENIH, Pomocnik::VRSTE_U_GENITIVU['konkurs']), 'Trenutno nema otvorenih konkursa.'],
            'nema otvorenih obuka' => [sprintf(Pomocnik::NEMA_OTVORENIH, Pomocnik::VRSTE_U_GENITIVU['obuka']), 'Trenutno nema otvorenih obuka.'],
        ];
    }

    #[Test]
    #[DataProvider('tekstovi')]
    public function tekst_je_kakav_je_u_dokumentu(string $tekst, string $uDokumentu): void
    {
        $this->assertSame($uDokumentu, $tekst);
    }

    // Ime ostaje „Vudar pomoćnik" (57/T9).
    #[Test]
    public function ime_pomocnika_ostaje(): void
    {
        $this->assertSame('Vudar pomoćnik', PrikazPomocnika::NASLOV);
        $this->assertSame('Pitaj Vudar pomoćnika', PrikazPomocnika::PITAJ_VUDAR);
    }

    // Isti propis koji važi za odgovor modela važi i za gotov tekst: nema budućeg vremena, obećanja ni „nema podatka" (provera sa stavkom kalendara u kontekstu je najstroža).
    #[Test]
    #[DataProvider('tekstovi')]
    public function gotov_tekst_prolazi_proveru_koja_ne_pusta_buducnost_ni_obecanja(string $tekst, string $uDokumentu): void
    {
        $razlog = (new ProveraOdgovora)->razlogOdbijanja($tekst, new Collection, new Collection, new Collection, new Collection([new KalendarStavka]));

        $this->assertSame($uDokumentu, $tekst);
        $this->assertNull($razlog, $tekst);
    }

    // Ogledalo: merilo nije prazno; isti tekst sa obećanjem ne prolazi.
    #[Test]
    #[DataProvider('obecanja')]
    public function tekst_sa_obecanjem_ne_prolazi_proveru(string $tekst, string $razlog): void
    {
        $this->assertSame($razlog, (new ProveraOdgovora)->razlogOdbijanja($tekst, new Collection, new Collection, new Collection, new Collection([new KalendarStavka])));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function obecanja(): array
    {
        return [
            'biće ovde čim se pojavi' => ['Za ovo trenutno nemam ništa na sajtu. Biće ovde čim se pojavi.', 'odgovor predviđa budućnost'],
            'obavestiće te' => ['Evo šta sam našao za tebe: obavestiće te kad se otvori.', 'odgovor predviđa budućnost'],
            'nema podatka' => ['Nemam podatak.', 'odgovor kaže da nema podatka'],
        ];
    }

    /** @return list<string> svi gotovi tekstovi pomoćnika koji su konstante (i elementi nizova) u klasama Pomocnik i PrikazPomocnika */
    private function tekstoviIzKonstanti(): array
    {
        $tekstovi = [];

        foreach ([Pomocnik::class, PrikazPomocnika::class] as $klasa) {
            foreach ((new ReflectionClass($klasa))->getConstants() as $vrednost) {
                foreach ((array) $vrednost as $deo) {
                    if (is_string($deo)) {
                        $tekstovi[] = $deo;
                    }
                }
            }
        }

        return $tekstovi;
    }

    // Paket 67/T14: poruke korisniku su bez roda („da se pripremiš", ne „da budeš spreman"), pa nijedan gotov tekst pomoćnika nema „spreman" ni „spremna".
    #[Test]
    public function nijedan_gotov_tekst_pomocnika_nema_spreman_ni_spremna(): void
    {
        $tekstovi = $this->tekstoviIzKonstanti();
        foreach (glob(dirname(__DIR__, 2).'/resources/views/javno/pomocnik*.blade.php') ?: [] as $pogled) {
            $tekstovi[] = (string) file_get_contents($pogled);
        }

        // Merilo prvo dokazuje da je obišlo gotove tekstove i poglede, i da prepoznaje oba oblika.
        $this->assertGreaterThan(30, count($tekstovi));
        $this->assertContains(Pomocnik::DOK_CEKAS, $tekstovi);
        $this->assertSame(1, preg_match('/\bsprem(?:an|na|no|ni|ne)\b/iu', 'Dok čekaš, ovo ti može pomoći da budeš spreman:'));
        $this->assertSame(1, preg_match('/\bsprem(?:an|na|no|ni|ne)\b/iu', 'Budi spremna.'));

        foreach ($tekstovi as $tekst) {
            $this->assertSame(0, preg_match('/\bsprem(?:an|na|no|ni|ne)\b/iu', $tekst), mb_substr($tekst, 0, 80));
        }

        $this->assertSame('Dok čekaš, ovo ti može pomoći da se pripremiš:', Pomocnik::DOK_CEKAS);
    }
}
