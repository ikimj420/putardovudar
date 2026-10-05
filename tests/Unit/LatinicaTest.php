<?php

namespace Tests\Unit;

use App\Support\Latinica;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LatinicaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function iz_paketa(): array
    {
        return [
            'rečenica' => ['Конкурс за стипендије', 'Konkurs za stipendije'],
            'sve velika slova' => ['ЉУБАВ', 'LJUBAV'],
            'prvo veliko slovo' => ['Љубав', 'Ljubav'],
        ];
    }

    #[Test]
    #[DataProvider('iz_paketa')]
    public function primeri_iz_paketa(string $cirilica, string $latinica): void
    {
        $this->assertSame($latinica, Latinica::izCirilice($cirilica));
    }

    #[Test]
    public function svih_trideset_slova_malih_i_velikih(): void
    {
        $mala = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'đ', 'е' => 'e', 'ж' => 'ž', 'з' => 'z', 'и' => 'i',
            'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'ћ' => 'ć', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'č', 'џ' => 'dž', 'ш' => 'š'];

        $this->assertCount(30, $mala);

        foreach ($mala as $cirilica => $latinica) {
            $this->assertSame($latinica, Latinica::izCirilice($cirilica), $cirilica);
            // Veliko slovo samo za sebe: dvoslovi dobijaju samo prvo veliko slovo.
            $this->assertSame(mb_strtoupper(mb_substr($latinica, 0, 1)).mb_substr($latinica, 1), Latinica::izCirilice(mb_strtoupper($cirilica)), mb_strtoupper($cirilica));
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function dvoslovi(): array
    {
        return [
            'LJ u velikoj reči' => ['ЉУДИ', 'LJUDI'],
            'NJ u velikoj reči' => ['ЊЕГОВ', 'NJEGOV'],
            'DŽ u velikoj reči' => ['ЏАК', 'DŽAK'],
            'dvoslov na kraju velike reči' => ['ПЕЊ', 'PENJ'],
            'dvoslov u sredini velike reči' => ['КОЊИ', 'KONJI'],
            'Nj u maloj reči' => ['Њива', 'Njiva'],
            'Dž u maloj reči' => ['Џак', 'Džak'],
            'samo jedno slovo' => ['Љ', 'Lj'],
            'između znakova' => ['(Љ)', '(Lj)'],
            'dve reči različito' => ['ЉУБАВ и Љубав', 'LJUBAV i Ljubav'],
            'sa crticom' => ['ЉУБАВ-Њихова', 'LJUBAV-Njihova'],
            'đ i ć ne prave dvoslove' => ['ЂАЦИ и Ћуприја', 'ĐACI i Ćuprija'],
            'mala slova uvek mala' => ['љубав ЉУБАВ', 'ljubav LJUBAV'],
        ];
    }

    #[Test]
    #[DataProvider('dvoslovi')]
    public function velika_i_mala_slova_dvoslova(string $cirilica, string $latinica): void
    {
        $this->assertSame($latinica, Latinica::izCirilice($cirilica));
    }

    // Ogledalo: tekst koji je već latinicom ostaje isti, znak po znak.
    #[Test]
    public function latinica_ostaje_ista_znak_po_znak(): void
    {
        foreach ([
            'Konkurs za stipendije', 'LJUBAV', 'Ljubav', 'Đurđevdan, šuma, čaj, ćup, žaba, ljubav, njiva, džak',
            '12.10.2026. – „navodnici“ (zagrade) 50% & #oznaka', 'Windows 11', "dva\nreda\ttab", '', ' ', '😀 emoji',
            'https://primer.rs/konkurs?x=1&y=2',
        ] as $tekst) {
            $this->assertSame($tekst, Latinica::izCirilice($tekst));
        }
    }

    #[Test]
    public function mesavina_pisama_menja_samo_cirilicu(): void
    {
        $this->assertSame('Windows 11 i Ljubav, Beograd 2026.', Latinica::izCirilice('Windows 11 и Љубав, Beograd 2026.'));
    }

    #[Test]
    public function posle_pretvaranja_nema_nijednog_cirilicnog_znaka_srpske_azbuke(): void
    {
        $azbuka = 'АБВГДЂЕЖЗИЈКЛЉМНЊОПРСТЋУФХЦЧЏШабвгдђежзијклљмнњопрстћуфхцчџш';

        $this->assertSame(1, preg_match('/\p{Cyrillic}/u', $azbuka));
        $this->assertSame(0, preg_match('/\p{Cyrillic}/u', Latinica::izCirilice($azbuka)));
    }

    #[Test]
    public function pretvaranje_dvaput_daje_isto_kao_jednom(): void
    {
        $tekst = 'Листа прелиминарних резултата ЉУБАВ Џак';

        $this->assertSame(Latinica::izCirilice($tekst), Latinica::izCirilice(Latinica::izCirilice($tekst)));
    }

    // Odluka: slovo van srpske azbuke (ruska, makedonska) ostaje kakvo je, da se tekst ne kvari pogađanjem.
    #[Test]
    public function slovo_van_srpske_azbuke_ostaje(): void
    {
        $this->assertSame('Ы Щ i Ѕ', Latinica::izCirilice('Ы Щ и Ѕ'));
    }
}
