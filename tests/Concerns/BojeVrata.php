<?php

namespace Tests\Concerns;

// Boje vrata koje važe na strani: osnovne iz :root, pa one koje menja body[data-strana]; čita se iz javno.css, ne iz kopije u testu.
trait BojeVrata
{
    /** @return array<string, string> npr. „svetlo-1" => „#ffe7b0", poređano po imenu */
    protected function bojeVrata(?string $strana): array
    {
        $stil = $this->stilBezKomentara();
        $telo = [];

        preg_match('/\n:root \{([^}]*)\}/', $stil, $osnovno);
        $this->assertNotEmpty($osnovno, 'u javno.css nema :root');
        $telo[] = $osnovno[1];

        if ($strana !== null) {
            preg_match('/\nbody\[data-strana="'.$strana.'"\] \{([^}]*)\}/', $stil, $strane);
            $this->assertNotEmpty($strane, $strana);
            $telo[] = $strane[1];
        }

        $boje = [];
        foreach ($telo as $deo) {
            preg_match_all('/--vrata-([a-z0-9-]+): (#[0-9a-f]{6});/', $deo, $nalazi, PREG_SET_ORDER);
            foreach ($nalazi as [, $ime, $vrednost]) {
                $boje[$ime] = $vrednost;
            }
        }
        ksort($boje);

        return $boje;
    }

    /** @return array{0: float, 1: float, 2: float} */
    protected function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
    }

    protected function osvetljenost(string $hex): float
    {
        [$r, $g, $b] = array_map(fn (float $v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $this->rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    protected function mesavina(string $a, string $b, float $t): string
    {
        return '#'.implode('', array_map(fn (float $x, float $y) => str_pad(dechex((int) round(($x + ($y - $x) * $t) * 255)), 2, '0', STR_PAD_LEFT), $this->rgb($a), $this->rgb($b)));
    }

    /**
     * Kontrast tamnog teksta oznake (po podrazumevanju „noć") sa dna njenog teksta (najtamnije mesto pod slovima): svetlo ide odozgo (60) nadole (242) kroz tri boje.
     * Model je proveren u pregledaču: razlika od izmerenog položaja je ispod 0,6 jedinice slike (1 px).
     *
     * @return list<float> kontrast svake od šest oznaka, redom
     */
    protected function kontrasti(?string $strana, int $sirinaVrata, string $tekst = '#270f43'): array
    {
        $boje = $this->bojeVrata($strana);
        $jedinica = $sirinaVrata / 220;
        $slova = $this->osvetljenost($tekst);
        $kontrasti = [];

        foreach (range(1, 6) as $broj) {
            $dno = 0.30 * 270 + $broj * 44 / $jedinica - 12 / $jedinica;
            $t = min(1, max(0, ($dno - 60) / (242 - 60)));
            $boja = $t <= 0.55 ? $this->mesavina($boje['svetlo-1'], $boje['svetlo-2'], $t / 0.55) : $this->mesavina($boje['svetlo-2'], $boje['svetlo-3'], ($t - 0.55) / 0.45);
            $kontrasti[] = ($this->osvetljenost($boja) + 0.05) / ($slova + 0.05);
        }

        return $kontrasti;
    }
}
