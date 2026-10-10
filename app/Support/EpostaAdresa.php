<?php

namespace App\Support;

// Jedino mesto koje kaže šta je ispravna adresa e-pošte organizacije: admin je proverava pri snimanju,
// a sajt pre nego što od nje napravi vezu „mailto:". Namerno uže od RFC-a: bez „?", razmaka i dvostrukih tačaka.
final class EpostaAdresa
{
    public const OBRAZAC = '/^(?!\.)(?!.*\.\.)[A-Za-z0-9._%+-]+(?<!\.)@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/D';

    public static function jeIspravna(?string $vrednost): bool
    {
        $adresa = trim((string) $vrednost);

        return $adresa !== '' && strlen($adresa) <= 254 && preg_match(self::OBRAZAC, $adresa) === 1;
    }
}
