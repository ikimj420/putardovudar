<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\TestCase;

// Paket 80/T3: „Pomoćnik radi na našem računaru i to ne šalje drugim firmama" — pomoćnik i „Napiši moj" govore samo sa Ollamom, a ona je podrazumevano na ovom računaru.
// Adresa Ollame je podešavanje okruženja (OLLAMA_ADRESA): ovde se meri podrazumevana i kod, ne vrednost iz nečijeg .env.
class PomocnikJeLokalanTest extends TestCase
{
    use BezLokalnogOkruzenja;

    #[Test]
    public function podrazumevana_adresa_ollame_je_ovaj_racunar(): void
    {
        $this->assertSame('127.0.0.1', parse_url(config('ollama.adresa'), PHP_URL_HOST));
    }

    // Kod pomoćnika ne zna za Claude ni za model obrade uvoza i sam ne zove nijednu adresu (to radi samo klasa Ollama).
    #[Test]
    public function kod_pomocnika_ne_poznaje_nijedan_spoljni_model_ni_adresu(): void
    {
        $fajlovi = [...glob(base_path('app/Services/Pomocnik/*.php')) ?: [], ...glob(base_path('app/Http/Controllers/Pomocnik*.php')) ?: []];
        $kod = array_map(fn (string $fajl) => (string) file_get_contents($fajl), $fajlovi);

        $this->assertGreaterThan(5, count($fajlovi), 'merilo mora da obiđe fajlove pomoćnika');
        $this->assertNotEmpty(array_filter($kod, fn (string $tekst) => str_contains($tekst, 'Ollama')), 'ogledalo: pomoćnik jeste vezan za Ollamu');

        foreach ($fajlovi as $i => $fajl) {
            $this->assertDoesNotMatchRegularExpression('/Claude|ModelObrade|anthropic|Http::|https?:\/\/(?!127\.0\.0\.1)/i', preg_replace('#(//|\*).*$#m', '', $kod[$i]) ?? '', basename($fajl));
        }
    }
}
