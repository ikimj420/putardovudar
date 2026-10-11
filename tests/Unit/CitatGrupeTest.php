<?php

namespace Tests\Unit;

use App\Enums\Grupa;
use App\Services\CitatGrupe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 53/T1: kad citata modela nema na strani, kod traži na strani rečenicu sa rečju grupe. Citat je uvek doslovan deo strane.
class CitatGrupeTest extends TestCase
{
    private function naStrani(Grupa $grupa, string $tekst): ?string
    {
        return (new CitatGrupe)->naStrani($grupa, $tekst);
    }

    #[Test]
    public function kratka_recenica_sa_recju_grupe_se_vraca_cela_i_doslovno(): void
    {
        $tekst = 'Konkurs. Rok je 20. oktobra 2026. godine. Konkurs je otvoren za sve studente Srbije. Hvala na pažnji svima.';

        $this->assertSame('Konkurs je otvoren za sve studente Srbije.', $this->naStrani(Grupa::Studenti, $tekst));
        // Ogledalo: druga grupa na istoj strani nema rečenicu, a prazna strana nema nijednu.
        $this->assertNull($this->naStrani(Grupa::Romi, $tekst));
        $this->assertNull($this->naStrani(Grupa::Studenti, ''));
    }

    /** @return array<string, array{0: string}> tekstovi koji pominju grupu, ali nemaju rečenicu koja se može uzeti za citat */
    public static function nemaRecenice(): array
    {
        return [
            'bez završne tačke' => ['Studenti prve godine imaju pravo na stipendiju'],
            'prekratko' => ['Za studente. Hvala.'],
            'spisak naslova velikim slovima' => ['Skip to Content Facebook Instagram Linkedin Studenti Stipendije Konkursi Vesti Kontakt.'],
            'grupa samo kao deo druge reči' => ['Studio za dizajn nudi nove kurseve svim zainteresovanim građanima Srbije.'],
        ];
    }

    #[Test]
    #[DataProvider('nemaRecenice')]
    public function tekst_bez_prave_recenice_ne_daje_citat(string $tekst): void
    {
        $this->assertNull($this->naStrani(Grupa::Studenti, $tekst), $tekst);
    }

    // Ogledalo prethodnog: ista reč u pravoj rečenici daje citat.
    #[Test]
    public function ista_reč_u_pravoj_recenici_daje_citat(): void
    {
        $this->assertSame('Studenti prve godine imaju pravo na stipendiju.', $this->naStrani(Grupa::Studenti, 'Studenti prve godine imaju pravo na stipendiju.'));
        $this->assertSame('Za sve studente Srbije je otvoren konkurs.', $this->naStrani(Grupa::Studenti, 'Za sve studente Srbije je otvoren konkurs.'));
    }

    // Rečenica koja kaže kome je namenjeno ima prednost nad prvom koja grupu samo pominje.
    #[Test]
    public function recenica_sa_namenom_ima_prednost_nad_prvom_koja_samo_pominje_grupu(): void
    {
        $tekst = 'Prošle godine je bilo mnogo studenata na predavanju. Konkurs je otvoren za sve studente. Studenti su bili zadovoljni.';

        $this->assertSame('Konkurs je otvoren za sve studente.', $this->naStrani(Grupa::Studenti, $tekst));
        // Bez rečenice sa namenom uzima se prva.
        $this->assertSame('Prošle godine je bilo mnogo studenata na predavanju.', $this->naStrani(Grupa::Studenti, 'Prošle godine je bilo mnogo studenata na predavanju. Studenti su bili zadovoljni.'));
    }

    // Rečenica koja staje u 300 znakova se vraća cela, i kad ima više od 10 reči pre i 12 posle reči grupe.
    #[Test]
    public function recenica_do_najvise_znakova_se_ne_seče_ni_kad_ima_mnogo_reci(): void
    {
        $recenica = 'Ovo je rečenica sa mnogo reči koje stoje pre nego što stigne reč studente, a posle nje ima još mnogo reči koje se ne seku iako ih je više od dvanaest.';

        $this->assertLessThanOrEqual(300, mb_strlen($recenica));
        $this->assertGreaterThan(22, count(explode(' ', $recenica)));
        $this->assertSame($recenica, $this->naStrani(Grupa::Studenti, 'Uvod o konkursu. '.$recenica));
    }

    // Duga rečenica se seče: tačno 10 reči pre i 12 reči posle reči grupe, doslovno i najviše 300 znakova.
    #[Test]
    public function duga_recenica_se_seče_na_deset_reci_pre_i_dvanaest_posle(): void
    {
        $pre = implode(' ', array_map(fn (int $i) => 'pre'.$i, range(1, 40)));
        $posle = implode(' ', array_map(fn (int $i) => 'posle'.$i, range(1, 40)));
        $recenica = 'Početak '.$pre.' studente '.$posle.' kraj.';
        $tekst = 'Uvod. '.$recenica;

        $citat = $this->naStrani(Grupa::Studenti, $tekst);

        $this->assertNotNull($citat);
        $this->assertStringContainsString($citat, $recenica);
        $reci = explode(' ', $citat);
        $this->assertSame(10, array_search('studente', $reci));
        $this->assertSame(10 + 1 + 12, count($reci));
        $this->assertSame('pre31', $reci[0]);
        $this->assertSame('posle12', $reci[22]);
        $this->assertLessThanOrEqual(300, mb_strlen($citat));
    }

    /** @return string niz različitih reči (paket 63: isti niz od četiri reči dvaput nije rečenica), svaka sa brojem */
    private function razlicitiRecnik(string $osnova, int $od, int $do): string
    {
        return implode(' ', array_map(fn (int $i) => $osnova.$i, range($od, $do)));
    }

    // Prozor ne prelazi 300 znakova ni kad su reči duge.
    #[Test]
    public function prozor_se_skracuje_kad_prelazi_najvise_znakova(): void
    {
        $duga = 'dugačkarečdugačkarečdugačkareč';
        $recenica = $this->razlicitiRecnik($duga, 1, 12).' studente '.$this->razlicitiRecnik($duga, 13, 24).' kraj.';

        $citat = $this->naStrani(Grupa::Studenti, $recenica);

        $this->assertNotNull($citat);
        $this->assertLessThanOrEqual(300, mb_strlen($citat));
        $this->assertStringContainsString('studente', $citat);
        $this->assertStringContainsString($citat, $recenica);
    }

    // Levo se staje posle natpisa sa dvotačkom i pre niza reči velikim slovom (meni), da citat ne počinje otpadom sa vrha strane.
    #[Test]
    public function levo_se_staje_posle_natpisa_i_pre_menija(): void
    {
        $sirina = $this->razlicitiRecnik('stvar', 1, 40).' ';
        $meni = 'Skip to content Facebook Instagram Linkedin Lista preliminarnih rezultata za nagrađivanje studenata '.$sirina.'do kraja.';
        $natpis = 'Finansijska podrška: do 10.000 evra po projektu Oblast: studenti i nastavnici '.$sirina.'do kraja.';

        $this->assertStringStartsWith('Lista preliminarnih', (string) $this->naStrani(Grupa::Studenti, $meni));
        $this->assertStringNotContainsString('Facebook', (string) $this->naStrani(Grupa::Studenti, $meni));
        $this->assertStringStartsWith('studenti i nastavnici', (string) $this->naStrani(Grupa::Studenti, $natpis));
    }

    // Paket 63/T1: blok „Povezano" i podnožje (u tekstu strane bez tačaka, pa liče na jednu dugu rečenicu) ne daju citat; izmereno na snimku COST poziva.
    private const BLOK_POVEZANO = 'Podeli sa drugima Povezano COST poziv 2026: Podrška za nove evropske mreže istraživača COST poziv 2026: Podrška za nove evropske mreže istraživača Dve stipendije za punu školarinu za master studije Umetnost i dizajn video-igara Dve stipendije za punu školarinu za master studije Umetnost i dizajn video-igara Poziv za dostavljanje ponuda: EU VALUES AND POLICIES IN SERBIA Search for: Sektori BIZNIS I INOVACIJE Naša web stranica koristi kolačiće kako bi vam pružila najbolje moguće iskustvo.';

    #[Test]
    public function blok_povezano_ne_daje_citat_i_kad_pominje_grupu(): void
    {
        $poziv = 'Rok za podnošenje predloga je 28. oktobar 2026. godine. Predlog podnosi glavni predlagač u ime međunarodne mreže pojedinaca. ';

        $this->assertNull($this->naStrani(Grupa::MasterDoktorski, $poziv.self::BLOK_POVEZANO));
        // Ogledalo: ista strana, ali sa rečenicom u pozivu koja pominje grupu — citat je ta rečenica, ne blok.
        $this->assertSame('Konkurs je otvoren za master studente iz Srbije.', $this->naStrani(Grupa::MasterDoktorski, $poziv.'Konkurs je otvoren za master studente iz Srbije. '.self::BLOK_POVEZANO));
    }

    /** @return array<string, array{0: string, 1: bool}> „rečenica" i da li daje citat za grupu studenti */
    public static function recenicaSaBlokom(): array
    {
        return [
            'reč Povezano velikim slovom' => ['Povezano sa ovim programom je i konkurs za studente koji studiraju u inostranstvu.', false],
            'ista reč malim slovom je obična reč' => ['Ovaj konkurs za studente je povezano sa programom razmene u inostranstvu.', true],
            'isti niz od tačno četiri reči dvaput' => ['Konkurs za studente prve godine je otvoren, a konkurs za studente prve nije.', false],
            'isti niz od tri reči dvaput' => ['Konkurs za studente prve godine je otvoren, a ovaj za studente prve nije.', true],
            'dve iste reči nisu niz' => ['Konkurs je za studente, a nagrada za studente je velika.', true],
        ];
    }

    #[Test]
    #[DataProvider('recenicaSaBlokom')]
    public function recenica_je_blok_veza_samo_sa_recju_povezano_ili_istim_nizom_od_cetiri_reci_dvaput(string $recenica, bool $dajeCitat): void
    {
        $citat = $this->naStrani(Grupa::Studenti, $recenica);

        $this->assertSame($dajeCitat, $citat !== null, $recenica);
    }

    #[Test]
    public function citat_se_traži_i_za_ostale_grupe_istim_recnikom(): void
    {
        $tekst = 'Program je za organizacije civilnog društva iz cele Srbije. Poziv je otvoren za omladinske organizacije i institucije.';

        $this->assertSame('Program je za organizacije civilnog društva iz cele Srbije.', $this->naStrani(Grupa::Organizacije, $tekst));
        $this->assertSame('Poziv je otvoren za omladinske organizacije i institucije.', $this->naStrani(Grupa::Organizacije, 'Uvod o programu. Poziv je otvoren za omladinske organizacije i institucije.'));
        $this->assertNull($this->naStrani(Grupa::Povratnici, $tekst));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> citat modela, tekst strane i da li citat stoji samo u bloku veza */
    public static function citatiModela(): array
    {
        $poziv = 'Rok za podnošenje predloga je 28. oktobar 2026. godine. Predlog podnosi glavni predlagač u ime mreže pojedinaca. ';
        $stipendije = 'Dve stipendije za punu školarinu za master studije';

        return [
            'citat samo u bloku Povezano' => [$stipendije, $poziv.self::BLOK_POVEZANO, true],
            'ista rečenica u telu teksta' => [$stipendije, $poziv.$stipendije.' su otvorene za sve prijavljene. '.self::BLOK_POVEZANO, false],
            'rečenica u telu i u bloku' => ['Konkurs je otvoren za master studente iz Srbije.', $poziv.'Konkurs je otvoren za master studente iz Srbije. Povezano Konkurs je otvoren za master studente iz Srbije. Konkurs je otvoren za master studente iz Srbije.', false],
            'citat velikim i malim slovima i višak razmaka' => ['  dve STIPENDIJE za punu školarinu   za master studije ', $poziv.self::BLOK_POVEZANO, true],
            'bez bloka nema šta da se odbije' => [$stipendije, $poziv.$stipendije.' su otvorene.', false],
            'citata nema na strani' => ['Citat koga nema nigde', $poziv.self::BLOK_POVEZANO, false],
            'prazan citat' => ['', self::BLOK_POVEZANO, false],
            'blok je pre tela, pa citat stoji i u telu' => [$stipendije, 'Povezano '.$stipendije.' Umetnost i dizajn video-igara Dve stipendije za punu školarinu za master studije Umetnost. Rok je 28. oktobar 2026. '.$stipendije.' su otvorene za sve prijavljene.', false],
            'citat je deo reči u bloku' => ['stipendij', $poziv.self::BLOK_POVEZANO, false],
            'podnožje sa istim nizom od četiri reči dvaput' => ['najbolje moguće iskustvo u pregledaču', 'Rok je 20. oktobar 2026. godine. Naša web stranica koristi kolačiće da bi pružila najbolje moguće iskustvo u pregledaču Naša web stranica koristi kolačiće da bi pružila najbolje moguće iskustvo u pregledaču', true],
        ];
    }

    // Paket 67 (PITANJA-63.md 3): citat koji vrati model, a stoji samo u bloku „Povezano" ili podnožju, ne dokazuje grupu; isto pravilo kao za rečenicu koju traži kod.
    #[Test]
    #[DataProvider('citatiModela')]
    public function citat_modela_koji_stoji_samo_u_bloku_veza_se_prepoznaje(string $citat, string $tekst, bool $samoUBloku): void
    {
        $this->assertSame($samoUBloku, (new CitatGrupe)->samoUBlokuVeza($citat, $tekst));
    }

    // Citat preko granice rečenice ne stoji ni u jednom delu: ovo pravilo ne kaže ništa, pa ostaje kako je bilo.
    #[Test]
    public function citat_preko_granice_recenice_nije_u_bloku_veza(): void
    {
        $this->assertFalse((new CitatGrupe)->samoUBlokuVeza('Rok je 28. oktobar. Predlog podnosi glavni', 'Rok je 28. oktobar. Predlog podnosi glavni predlagač. '.self::BLOK_POVEZANO));
    }
}
