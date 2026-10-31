<?php

namespace App\Services;

// Razrešavanje imena u adrese je zasebno da test ne ide na pravi DNS: svaki test ima svog razrešivača (tests/Podrska/LazniRazresivac.php).
interface RazresivacImena
{
    /** @return list<string> sve adrese (IPv4 i IPv6) na koje se ime razrešava; prazno kad se ne razrešava */
    public function adrese(string $ime): array;
}
