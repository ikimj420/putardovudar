<?php

namespace Tests\Feature;

use App\Models\KalendarStavka;
use App\Services\Pomocnik\Pomocnik;
use App\Services\Pomocnik\ProveraOdgovora;
use App\Support\PrikazPomocnika;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Paket 65/T1: gotovi tekstovi pomoćnika su ljudskiji (Ivan odobrio smer 10.10.2026., tekstovi su Coworkovi iz ZA-KASNIJE-POMOCNIK.md), a ne obećavaju:
// provera odgovora modela ne pušta buduće vreme, pa ni gotov tekst ne sme da ga ima.
class PomocnikGotoviTekstoviTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> naziv, tekst iz dokumenta */
    public static function tekstovi(): array
    {
        return [
            'uvod' => [PrikazPomocnika::UVOD, 'Ćao! Pitaj me za posao, stipendiju, konkurs ili gde da nađeš pomoć. Kažem ti samo ono što piše na sajtu, i uvek uz link.'],
            'kad nađe prilike' => [Pomocnik::SABLON_NASLOV, 'Evo šta sam našao za tebe:'],
            'kad nađe vodiče' => [Pomocnik::SABLON_NASLOV_VODICI, 'Evo šta sam našao za tebe:'],
            'kad nađe organizacije' => [Pomocnik::SABLON_NASLOV_ORGANIZACIJE, 'Evo šta sam našao za tebe:'],
            'kad nađe kalendar' => [Pomocnik::SABLON_NASLOV_KALENDAR, 'Evo šta sam našao za tebe:'],
            'kad nema ništa' => [Pomocnik::NEMAM_PODATAK, 'Za ovo trenutno nemam ništa na sajtu. Probaj da pitaš drugim rečima ili pogledaj Vodiče.'],
            'kad ne sme' => [Pomocnik::NE_MOGU_DA_POMOGNEM, 'U tome ti ne mogu pomoći. Ako ti treba pomoć uživo, pogledaj Organizacije.'],
            'kad ne radi' => [PrikazPomocnika::NE_RADI, 'Izvini, trenutno ne radim. Probaj malo kasnije.'],
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
}
