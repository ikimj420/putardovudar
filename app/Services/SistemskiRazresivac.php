<?php

namespace App\Services;

// gethostbynamel čita i /etc/hosts i brojčane oblike („2130706433" je 127.0.0.1), pa ih vidi isto kao veza koju curl otvara; IPv6 dolazi iz DNS-a.
final class SistemskiRazresivac implements RazresivacImena
{
    public function adrese(string $ime): array
    {
        $adrese = gethostbynamel($ime) ?: [];

        foreach (@dns_get_record($ime, DNS_AAAA) ?: [] as $zapis) {
            if (is_string($zapis['ipv6'] ?? null)) {
                $adrese[] = $zapis['ipv6'];
            }
        }

        return array_values(array_unique($adrese));
    }
}
