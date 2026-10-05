<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PorukeProvereLaravelaSuNaSrpskomUPotpunostiTest extends TestCase
{
    private const GRUPE = ['auth', 'pagination', 'passwords', 'validation'];

    // „custom" i „attributes" su prazni primeri iz okvira, ne poruke.
    private const PRIMERI = ['custom.attribute-name.rule-name', 'attributes'];

    // laravel-lang nema srpski prevod za ova pravila (ne koriste se u adminu); test dole pada čim se prevedu.
    private const BEZ_PREVODA = ['array_keys', 'base64', 'encoding'];

    /** @return array<string, array{0: string}> */
    public static function grupe(): array
    {
        return array_combine(self::GRUPE, array_map(fn (string $grupa) => [$grupa], self::GRUPE));
    }

    #[Test]
    #[DataProvider('grupe')]
    public function srpski_prevod_ima_svaki_kljuc_koji_okvir_ima_na_engleskom(string $grupa): void
    {
        $engleski = $this->ravno($this->ucitaj(dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/'.$grupa.'.php'));
        $srpski = $this->ravno($this->ucitaj(dirname(__DIR__, 2).'/lang/sr_Latn/'.$grupa.'.php'));

        $this->assertNotSame([], $engleski, 'Merilo nije obišlo nijedan engleski ključ.');
        $this->assertSame([], array_keys(array_diff_key($engleski, $srpski)), $grupa.': ključevi bez prevoda');
        $this->assertSame([], array_keys(array_diff_key($srpski, $engleski)), $grupa.': ključevi kojih okvir nema');
    }

    // Ogledalo: prevod nije engleski original i nije ćirilicom.
    #[Test]
    #[DataProvider('grupe')]
    public function nijedna_poruka_nije_na_engleskom_ni_cirilicom(string $grupa): void
    {
        $engleski = $this->ravno($this->ucitaj(dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/'.$grupa.'.php'));
        $srpski = $this->ravno($this->ucitaj(dirname(__DIR__, 2).'/lang/sr_Latn/'.$grupa.'.php'));
        $proverenih = 0;

        foreach ($srpski as $kljuc => $poruka) {
            if ($this->jePrimer($kljuc) || in_array($kljuc, self::BEZ_PREVODA, true)) {
                continue;
            }

            $proverenih++;
            $this->assertNotSame($engleski[$kljuc] ?? null, $poruka, $grupa.'.'.$kljuc.' je još na engleskom');
            $this->assertSame(0, preg_match('/\p{Cyrillic}/u', (string) $poruka), $grupa.'.'.$kljuc.' je ćirilicom');
        }

        $this->assertGreaterThan(0, $proverenih);
    }

    // Rupa: kad neko prevede ove tri poruke, test pada i izuzeci se brišu iz BEZ_PREVODA.
    #[Test]
    public function tri_poruke_bez_prevoda_su_i_dalje_na_engleskom(): void
    {
        $engleski = $this->ravno($this->ucitaj(dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'));
        $srpski = $this->ravno($this->ucitaj(dirname(__DIR__, 2).'/lang/sr_Latn/validation.php'));

        foreach (self::BEZ_PREVODA as $kljuc) {
            $this->assertSame($engleski[$kljuc], $srpski[$kljuc], $kljuc.' je preveden: skini ga sa BEZ_PREVODA');
        }
    }

    #[Test]
    public function merilo_vidi_kljuc_koji_fali_i_poruku_koja_je_ostala_na_engleskom(): void
    {
        $engleski = $this->ravno(['required' => 'The :attribute field is required.', 'url' => 'The :attribute field must be a valid URL.']);
        $srpski = $this->ravno(['required' => 'The :attribute field is required.']);

        $this->assertSame(['url'], array_keys(array_diff_key($engleski, $srpski)));
        $this->assertSame($engleski['required'], $srpski['required']);
    }

    private function jePrimer(string $kljuc): bool
    {
        foreach (self::PRIMERI as $izuzetak) {
            if (str_starts_with($kljuc, $izuzetak)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function ucitaj(string $fajl): array
    {
        return require $fajl;
    }

    /**
     * @param  array<string, mixed>  $niz
     * @return array<string, mixed>
     */
    private function ravno(array $niz, string $pre = ''): array
    {
        $rezultat = [];

        foreach ($niz as $kljuc => $vrednost) {
            if (is_array($vrednost) && $vrednost !== []) {
                $rezultat += $this->ravno($vrednost, $pre.$kljuc.'.');
            } else {
                $rezultat[$pre.$kljuc] = $vrednost;
            }
        }

        return $rezultat;
    }
}
