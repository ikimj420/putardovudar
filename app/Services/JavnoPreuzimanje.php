<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

// Jedino mesto gde se preuzima strana izvora (obrada, provera, uvoz). Preusmeravanja se prate ručno, jer se svaka adresa proverava pre veze, i prva i ona posle preusmeravanja.
// Ime se razrešava pre veze, ne uz nju: promena odgovora DNS-a između provere i veze ovde nije pokrivena.
final class JavnoPreuzimanje
{
    public function __construct(private readonly JavnaAdresa $javna) {}

    /** @throws StranaNedostupna */
    public function preuzmi(string $adresa): Response
    {
        for ($preusmeravanja = 0; ; $preusmeravanja++) {
            if (! $this->javna->jeJavna($adresa)) {
                throw new AdresaNijeJavna;
            }

            $odgovor = Http::timeout(config('uvoz.vreme_cekanja'))->withUserAgent(config('uvoz.korisnicki_agent'))->withoutRedirecting()->get($adresa);
            $lokacija = $odgovor->header('Location');

            if (! $odgovor->redirect() || $lokacija === '') {
                return $odgovor;
            }

            if ($preusmeravanja >= (int) config('uvoz.najvise_preusmeravanja')) {
                throw new StranaNedostupna('strana ima previše preusmeravanja');
            }

            try {
                $adresa = (string) UriResolver::resolve(new Uri($adresa), new Uri($lokacija));
            } catch (InvalidArgumentException) {
                throw new StranaNedostupna('strana preusmerava na neispravnu adresu');
            }
        }
    }
}
