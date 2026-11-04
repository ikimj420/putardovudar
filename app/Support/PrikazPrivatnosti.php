<?php

namespace App\Support;

// Tekstovi strane „Privatnost" (paket 80; kraće rečenice su paket 84/T3, Ivan 30.10.2026.). Rečenice su iz paketa, bez one koja nije tačna: „Sajt ne traži ime, mejl ni broj telefona" —
// „Napiši moj" traži ime, adresu i telefon (PITANJA-80.md). Svaka tvrdnja ovde ima test u PrivatnostTvrdnjeTest ili UpisanoSeNeCuvaTest.
final class PrikazPrivatnosti
{
    public const NASLOV = 'Privatnost';

    public const UVOD = 'Kratko i jasno: šta sajt pamti, a šta ne.';

    public const NALOG = 'Ne treba ti nalog.';

    // Paket 100/T4 (Ivan, 05.11.2026.: sve ide preko Claude-a): rečenica o računaru više nije tačna. Anthropic za API: bez učenja po podrazumevanom i brisanje u 30 dana
    // (privacy.claude.com, članci 7996868 i 7996866, pročitano 05.11.2026.). Tekst je predlog iz paketa, Ivan ga odobrava (PITANJA-100.md).
    public const UPISANO = 'Ne čuvamo tvoja pitanja pomoćniku ni ono što upišeš u „Napiši moj". Ne koristimo ih ni za šta drugo. Da bi napisao odgovor, pomoćnik ih šalje Claude-u, firmi Anthropic. Anthropic ih ne koristi za učenje modela. Obično ih briše u roku od 30 dana.';

    public const PREDLOG = 'Kad predložiš priliku, čuvamo samo link i napomenu koje upišeš, da bismo proverili priliku. Ne upisuj lične podatke u napomenu.';

    public const TEHNICKI = 'Dok si na sajtu, server privremeno pamti adresu tvog uređaja i vrstu pregledača. To mu treba da bi sajt radio i da bi sprečio zloupotrebu.';

    public const KOLACICI = 'Sajt koristi samo kolačiće koji su potrebni da radi. Nema reklama ni praćenja.';

    public const DRUGI_SAJTOVI = 'Linkovi vode na druge sajtove (izvore prilika i organizacije). Oni imaju svoja pravila o privatnosti.';

    // Redom kako stoje na strani.
    public const PASUSI = [self::NALOG, self::UPISANO, self::PREDLOG, self::TEHNICKI, self::KOLACICI, self::DRUGI_SAJTOVI];
}
