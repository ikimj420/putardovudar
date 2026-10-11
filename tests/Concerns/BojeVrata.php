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
}
