<?php

namespace App\Services\Pomocnik;

use App\Support\PoredjenjeTeksta;

// Reči „vodič" i „organizacija" (u bilo kom padežu) ponavljaju vrstu strane koja se traži, pa ne sužavaju pretragu.
// Kad su jedine, pitanje je „šta imate": tada se izlistava sve objavljeno te vrste.
final readonly class OpsteReci
{
    public const VODIC = 'vodic';

    public const ORGANIZACIJA = 'organizacij';

    /** @param  list<string>  $kljucneReci */
    public function __construct(private array $kljucneReci) {}

    /** Svaki izraz bez reči koje počinju na $koren; izraz koji je bio samo takva reč otpada. @return list<string> */
    public function izrazi(string $koren): array
    {
        $izrazi = [];

        foreach ($this->kljucneReci as $izraz) {
            $reci = array_filter($this->reci($izraz), fn (string $rec) => ! str_starts_with($rec, $koren));

            if ($reci !== []) {
                $izrazi[] = implode(' ', $reci);
            }
        }

        return $izrazi;
    }

    public function sadrzi(string $koren): bool
    {
        foreach ($this->kljucneReci as $izraz) {
            foreach ($this->reci($izraz) as $rec) {
                if (str_starts_with($rec, $koren)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Ima reči i sve su „vodič" ili „organizacija": ništa ne sužava, pa se pita šta ima.
    public function jeSamoOpste(): bool
    {
        $reci = array_merge([], ...array_map(fn (string $izraz) => $this->reci($izraz), $this->kljucneReci));

        return $reci !== [] && array_filter($reci, fn (string $rec) => ! str_starts_with($rec, self::VODIC) && ! str_starts_with($rec, self::ORGANIZACIJA)) === [];
    }

    /** @return list<string> */
    private function reci(string $izraz): array
    {
        return array_values(array_filter(explode(' ', PoredjenjeTeksta::normalizuj($izraz)), fn (string $rec) => $rec !== ''));
    }
}
