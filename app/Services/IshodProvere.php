<?php

namespace App\Services;

use Carbon\CarbonImmutable;

// Šta je dnevna provera zaključila za jednu javnu priliku. $stranaStigla je false kad strana nije preuzeta (tada se ne briše beleška „strane nema").
final readonly class IshodProvere
{
    public const U_REDU = 'u_redu';

    public const U_NACRT = 'u_nacrt';

    public const NOV_ROK = 'nov_rok';

    // Strane nema prvi dan: samo se upisuje; nacrt postaje tek kad je nema i sledeći dan.
    public const NEDOSTUPNA = 'nedostupna';

    // Strana nije stigla (osim 404 i 410) ili strana nema šta da se poredi: ništa se ne menja.
    public const BEZ_PROVERE = 'bez_provere';

    public function __construct(
        public string $ishod,
        public string $razlog = '',
        public string $citat = '',
        public ?CarbonImmutable $rok = null,
        public bool $stranaStigla = true,
    ) {}
}
