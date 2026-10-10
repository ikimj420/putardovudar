<?php

namespace App\Support;

// Tekstovi o vodičima koje vidi posetilac, na jednom mestu (kao PrikazPrilike). Predlog je u PITANJA-18-22.md.
final class PrikazVodica
{
    public const NASLOV = 'Vodiči';

    public const NEMA_VODICA = 'Trenutno nema vodiča.';

    // Predlog; Ivan odobrava (PITANJA-23-25.md). Ne kaže da je vodič nacrt, jer bi to potvrdilo da zapis postoji.
    public const NEMA_STRANE = 'Ta strana ne postoji ili vodič više nije dostupan.';

    public const KORACI = 'Koraci';

    public const OZNAKA = 'Vodič';
}
