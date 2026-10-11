<?php

namespace Tests\Unit;

use App\Services\Pomocnik\KratakOdgovor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 65/T2: odgovor modela ima najviše dve rečenice; ono što je preko toga i nabrajanje se odseca, a ništa se ne dodaje.
class KratakOdgovorTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function odgovori(): array
    {
        return [
            'jedna rečenica ostaje' => ['Našao sam posao u skladištu u Nišu.', 'Našao sam posao u skladištu u Nišu.'],
            'dve rečenice ostaju' => ['Našao sam dva oglasa. Oba su za Niš.', 'Našao sam dva oglasa. Oba su za Niš.'],
            'tri rečenice postaju dve' => ['Da, ima prakse. U biblioteci je do 16. oktobra. A u opštini do 30. oktobra.', 'Da, ima prakse. U biblioteci je do 16. oktobra.'],
            'četiri rečenice postaju dve' => ['Prva. Druga. Treća. Četvrta.', 'Prva. Druga.'],
            'upitnik i uzvičnik završavaju rečenicu' => ['Ćao! Našao sam posao. Ima ga u Nišu.', 'Ćao! Našao sam posao.'],
            'nabrajanje sa crticama' => ["Našao sam tri konkursa:\n\n- Konkurs za priču, do 20.10.2026.\n- Konkurs za mlade, do 25.10.2026.\n- Nagrada Mladi talenat, do 31.10.2026.", 'Našao sam tri konkursa.'],
            'nabrajanje sa tačkama' => ["Našao sam tri stipendije:\n• Prva\n• Druga", 'Našao sam tri stipendije.'],
            'nabrajanje sa zvezdicama' => ["Našao sam stipendije:\n* Prva\n* Druga", 'Našao sam stipendije.'],
            'numerisano nabrajanje' => ["Evo šta ima:\n1. Prvo\n2) Drugo", 'Evo šta ima.'],
            'uvod od dve rečenice pa nabrajanje' => ["Ima posla. Za Niš je.\n- Radnik\n- Konobar", 'Ima posla. Za Niš je.'],
            'datum na kraju rečenice ne deli rečenicu' => ['Prošli put je bila otvorena od 22.12.2025. do 26.01.2026.', 'Prošli put je bila otvorena od 22.12.2025. do 26.01.2026.'],
            'datum sa tačkom posle dana' => ['Rok je do 15. novembra 2026. Prijave su otvorene. Ima ih tri.', 'Rok je do 15. novembra 2026. Prijave su otvorene.'],
            'novi red usred rečenice je razmak' => ["Našao sam posao\nu Nišu.", 'Našao sam posao u Nišu.'],
            'razmaci oko teksta' => ["  \n Našao sam posao.  \n", 'Našao sam posao.'],
            'crtica usred rečenice nije nabrajanje' => ['Posao je u Nišu - za radnika. Ima smene.', 'Posao je u Nišu - za radnika. Ima smene.'],
            'datum na kraju rečenice ne dobija drugu tačku' => ['Prva je do 16.10.2026., a druga do 30.10.2026..', 'Prva je do 16.10.2026., a druga do 30.10.2026.'],
            'dve tačke usred rečenice posle datuma' => ['Do 16.10.2026.. Pa još ovo.', 'Do 16.10.2026. Pa još ovo.'],
            'tri tačke ostaju' => ['Pogledaj i to... Pa ono.', 'Pogledaj i to... Pa ono.'],
            'prazan tekst' => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('odgovori')]
    public function odgovor_ima_najvise_dve_recenice_bez_nabrajanja(string $model, string $ocekivano): void
    {
        $this->assertSame($ocekivano, KratakOdgovor::skrati($model));
    }

    // Merilo prvo dokazuje da je obišlo i odgovore koji se skraćuju (neprazan skup), a ne samo one koji prolaze nepromenjeni.
    #[Test]
    public function merilo_obilazi_odgovore_koji_se_menjaju_i_one_koji_ostaju(): void
    {
        $promenjeni = array_filter(self::odgovori(), fn (array $par) => $par[0] !== $par[1] && $par[0] !== '' && trim($par[0]) !== $par[1]);
        $nepromenjeni = array_filter(self::odgovori(), fn (array $par) => $par[0] === $par[1] && $par[0] !== '');

        $this->assertGreaterThan(8, count($promenjeni));
        $this->assertGreaterThan(3, count($nepromenjeni));
    }

    #[Test]
    public function skracivanje_nikad_ne_dodaje_ni_jednu_rec(): void
    {
        foreach (self::odgovori() as [$model]) {
            $reci = fn (string $tekst) => array_filter(preg_split('/[^\p{L}\p{N}]+/u', $tekst) ?: []);

            $this->assertSame([], array_diff($reci(KratakOdgovor::skrati($model)), $reci($model)), $model);
        }
    }
}
