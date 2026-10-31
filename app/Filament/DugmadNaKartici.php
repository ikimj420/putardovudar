<?php

namespace App\Filament;

// Dva dugmeta dele širinu kartice (Filament ih na laptopu ne prelama, pa bi dva od po 100 % izašla iz kartice); jedno samo zauzima celu. Isti niz za sve spiskove kartica u adminu.
final class DugmadNaKartici
{
    public const SIRINA = ['style' => 'flex: 1 1 0; min-width: 0; justify-content: center;'];
}
