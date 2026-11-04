<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Paket 80/T3, prepisan u paketu 100 (Ivan, 05.11.2026.: „sve treba preko Claude-a"): pomoćnik i „Napiši moj" više ne rade na ovom računaru nego preko Claude-a, ali kod pomoćnika
// i dalje sam ne zove nijednu adresu: razgovor sa modelom ide kroz jednu klasu (Claude), a ostalo u pomoćniku su pretraga, provere i sklapanje. Merilo gleda kod bez komentara.
class PomocnikNeZoveNijednuAdresuSamTest extends TestCase
{
    #[Test]
    public function kod_pomocnika_ne_zna_za_adrese_ni_za_http_poziv(): void
    {
        $fajlovi = [...glob(base_path('app/Services/Pomocnik/*.php')) ?: [], ...glob(base_path('app/Http/Controllers/Pomocnik*.php')) ?: []];

        $this->assertGreaterThan(5, count($fajlovi), 'merilo mora da obiđe fajlove pomoćnika');
        $this->assertNotEmpty(array_filter($fajlovi, fn (string $fajl) => str_contains((string) file_get_contents($fajl), 'Claude')), 'ogledalo: pomoćnik jeste vezan za Claude');

        foreach ($fajlovi as $fajl) {
            $this->assertSame([], $this->nalazi((string) file_get_contents($fajl)), basename($fajl));
        }
    }

    // Ogledalo: merilo vidi adresu u tekstu, reč „anthropic" i poziv „Http::", a ne vidi komentar o njima.
    #[Test]
    public function merilo_prepoznaje_adresu_i_http_poziv_a_ne_komentar(): void
    {
        $this->assertNotSame([], $this->nalazi("<?php \$a = 'https://api.anthropic.com/v1/messages';"));
        $this->assertNotSame([], $this->nalazi("<?php \$a = 'http://127.0.0.1:11434';"));
        $this->assertNotSame([], $this->nalazi('<?php $a = "anthropic";'));
        $this->assertNotSame([], $this->nalazi("<?php \$a = Http::get('x');"));
        $this->assertNotSame([], $this->nalazi("<?php \$a = \\Illuminate\\Support\\Facades\\Http::get('x');"));
        $this->assertSame([], $this->nalazi("<?php\n// zove https://api.anthropic.com preko Http::post\n/** anthropic */\n\$a = 1;"));
    }

    /** @return list<string> */
    private function nalazi(string $kod): array
    {
        $tokeni = array_values(array_filter(token_get_all($kod), fn (mixed $token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $nalazi = [];

        foreach ($tokeni as $i => $token) {
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('#https?://[a-z0-9]|anthropic#i', $token[1]) === 1) {
                $nalazi[] = 'tekst '.$token[1];
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && preg_match('/(^|\\\\)Http$/', $token[1]) === 1 && is_array($tokeni[$i + 1] ?? null) && $tokeni[$i + 1][0] === T_DOUBLE_COLON) {
                $nalazi[] = 'Http::';
            }
        }

        return $nalazi;
    }
}
