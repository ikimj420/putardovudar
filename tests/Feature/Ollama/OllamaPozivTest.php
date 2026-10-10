<?php

namespace Tests\Feature\Ollama;

use App\Services\Ollama\OdgovorNijeJson;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OllamaPozivTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function odgovor(string $sadrzaj): mixed
    {
        return Http::response(['message' => ['role' => 'assistant', 'content' => $sadrzaj]]);
    }

    #[Test]
    public function salje_uputstvo_i_tekst_na_adresu_iz_podesavanja_uz_temperaturu_nula_i_vraca_json_kao_niz(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/chat' => $this->odgovor('{"vazi_pravilo": true, "razlog": "Zato."}')]);

        $niz = (new Ollama)->odgovoriJsonom('Kratko uputstvo.', 'Tekst strane.');

        $this->assertSame(['vazi_pravilo' => true, 'razlog' => 'Zato.'], $niz);

        Http::assertSent(function (Request $zahtev) {
            $telo = $zahtev->data();

            return $zahtev->url() === 'http://127.0.0.1:11434/api/chat'
                && $telo['model'] === 'qwen2.5:7b'
                && $telo['stream'] === false
                && $telo['format'] === 'json'
                && $telo['options']['temperature'] === 0
                && $telo['options']['num_ctx'] === 8192
                && $telo['messages'] === [['role' => 'system', 'content' => 'Kratko uputstvo.'], ['role' => 'user', 'content' => 'Tekst strane.']];
        });
    }

    // Ogledalo: promena podešavanja menja adresu, model i vreme čekanja, bez izmene koda.
    #[Test]
    public function adresa_model_i_vreme_cekanja_dolaze_iz_podesavanja(): void
    {
        config()->set('ollama.adresa', 'http://drugi-racunar.test:9999/');
        config()->set('ollama.model', 'drugi-model:1b');
        config()->set('ollama.vreme_cekanja', 7);
        $opcije = [];
        Http::fake(['*' => function (Request $zahtev, array $dobijene) use (&$opcije) {
            $opcije = $dobijene;

            return $this->odgovor('{}');
        }]);

        (new Ollama)->odgovoriJsonom('U', 'T');

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === 'http://drugi-racunar.test:9999/api/chat' && $zahtev->data()['model'] === 'drugi-model:1b');
        $this->assertSame(7, $opcije['timeout']);
    }

    // Pozivalac sme da skrati vreme čekanja (ostatak ukupnog vremena), nikad da ga produži preko podešavanja.
    #[Test]
    public function pozivalac_moze_da_skrati_vreme_cekanja_ali_ne_i_da_ga_produzi(): void
    {
        config()->set('ollama.vreme_cekanja', 7);
        $opcije = [];
        Http::fake(['*' => function (Request $zahtev, array $dobijene) use (&$opcije) {
            $opcije[] = $dobijene['timeout'];

            return $this->odgovor('{}');
        }]);
        $ollama = new Ollama;

        $ollama->odgovoriJsonom('U', 'T', 3);
        $ollama->odgovoriTekstom('U', 'T', 4);
        $ollama->odgovoriJsonom('U', 'T', 30);
        $ollama->odgovoriTekstom('U', 'T', 30);
        $ollama->odgovoriJsonom('U', 'T');
        $ollama->odgovoriJsonom('U', 'T', 0);

        $this->assertSame([3, 4, 7, 7, 7, 1], $opcije);
    }

    #[Test]
    public function prazan_json_objekat_je_ispravan_odgovor(): void
    {
        Http::fake(['*' => $this->odgovor('{}')]);

        $this->assertSame([], (new Ollama)->odgovoriJsonom('U', 'T'));
    }

    #[Test]
    public function veza_koja_ne_radi_je_nedostupna_ollama(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('nema veze')]);

        $this->expectException(OllamaNedostupna::class);
        (new Ollama)->odgovoriJsonom('U', 'T');
    }

    #[Test]
    public function http_greska_je_nedostupna_ollama(): void
    {
        Http::fake(['*' => Http::response('greška', 500)]);

        $this->expectException(OllamaNedostupna::class);
        (new Ollama)->odgovoriJsonom('U', 'T');
    }

    /** @return array<string, array{0: string}> */
    public static function odgovoriKojiNisuJsonObjekat(): array
    {
        return [
            'obican tekst' => ['Evo odgovora: da.'],
            'prazan' => [''],
            'broj' => ['5'],
            'lista' => ['[1, 2]'],
            'pokvaren json' => ['{"vazi_pravilo": tru'],
        ];
    }

    #[Test]
    #[DataProvider('odgovoriKojiNisuJsonObjekat')]
    public function odgovor_koji_nije_json_objekat_se_odbija_kao_odgovor_a_ne_kao_nedostupnost(string $sadrzaj): void
    {
        Http::fake(['*' => $this->odgovor($sadrzaj)]);

        $this->expectException(OdgovorNijeJson::class);
        (new Ollama)->odgovoriJsonom('U', 'T');
    }

    #[Test]
    public function odgovori_tekstom_salje_isti_poziv_bez_json_formata_i_vraca_tekst(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/chat' => $this->odgovor("  Ima jedna prilika.\n")]);

        $tekst = (new Ollama)->odgovoriTekstom('Uputstvo.', 'Zapisi.');

        $this->assertSame('Ima jedna prilika.', $tekst);

        Http::assertSent(function (Request $zahtev) {
            $telo = $zahtev->data();

            return $zahtev->url() === 'http://127.0.0.1:11434/api/chat'
                && $telo['model'] === 'qwen2.5:7b'
                && $telo['stream'] === false
                && ! array_key_exists('format', $telo)
                && $telo['options']['temperature'] === 0
                && $telo['messages'] === [['role' => 'system', 'content' => 'Uputstvo.'], ['role' => 'user', 'content' => 'Zapisi.']];
        });
    }

    // Ogledalo: JSON posao i dalje traži format json.
    #[Test]
    public function odgovori_jsonom_i_dalje_trazi_format_json(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/chat' => $this->odgovor('{"a": 1}')]);

        (new Ollama)->odgovoriJsonom('U.', 'T.');

        Http::assertSent(fn (Request $zahtev) => $zahtev->data()['format'] === 'json');
    }

    #[Test]
    public function odgovori_tekstom_prazan_odgovor_je_prazan_tekst_a_nedostupna_ollama_izuzetak(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/chat' => Http::response(['message' => []])]);

        $this->assertSame('', (new Ollama)->odgovoriTekstom('U.', 'T.'));

        Http::fake(['http://127.0.0.1:11434/api/chat' => fn () => throw new ConnectionException('nema veze')]);

        $this->expectException(OllamaNedostupna::class);

        (new Ollama)->odgovoriTekstom('U.', 'T.');
    }
}
