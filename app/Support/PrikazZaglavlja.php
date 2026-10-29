<?php

namespace App\Support;

// Tekstovi zaglavlja koje vidi posetilac, na jednom mestu (kao PrikazPrilike). Predlog je u PITANJA-26-38.md.
final class PrikazZaglavlja
{
    // Naziv dugmeta menija na telefonu; čita ga čitač ekrana, na dugmetu je samo ikona.
    public const OTVORI_MENI = 'Otvori meni';

    // Naziv putanje (ime sajta / odeljak / naslov) iznad naslova strane; čita ga čitač ekrana.
    public const PUTANJA = 'Putanja';

    // Ispod imena sajta (paket 57, tekst iz paketa).
    public const SLOGAN = 'Otvorena vrata';

    // Veza u meniju posle „Prilike" (paket 80, tekst iz paketa).
    public const STIPENDIJE = 'Stipendije';

    // Naziv podnožja (veze „Privatnost" i „Predloži priliku"); čita ga čitač ekrana (paket 80).
    public const PODNOZJE = 'Podnožje';
}
