<?php

namespace Tests\Feature\Claude;

use App\Services\Claude\Claude;
use App\Services\Model\ModelNedostupan;
use App\Services\Model\OdgovorNijeJson;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Paket 71/T1: Claude klasa je jedino mesto poziva modela (ModelObrade). Nijedan test ne zove pravi API: ključ je izmišljen, a zahtevi su lažirani.
class ClaudePozivTest extends TestCase
{
    private const KLJUC = 'probni-kljuc-nije-pravi-123';

    private const ADRESA = 'https://api.anthropic.com/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        config()->set('claude.kljuc', self::KLJUC);
        // Ovaj test meri kod, ne .env ove mašine: tu može da stoji drugi model, adresa ili isključeno razmišljanje.
        config()->set('claude.model', 'claude-haiku-5-5');
        config()->set('claude.adresa', 'https://api.anthropic.com');
        config()->set('claude.razmisljanje', null);
        config()->set('claude.ponavljanja_cekanje', [3, 10]);
    }

    /** @param  array<string, mixed>  $dopuna */
    private function odgovor(string $sadrzaj, array $dopuna = []): mixed
    {
        return Http::response([...['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $sadrzaj]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 80]], ...$dopuna]);
    }

    #[Test]
    public function salje_uputstvo_i_tekst_na_messages_api_sa_kljucem_u_zaglavlju_bez_temperature_i_vraca_json_kao_niz(): void
    {
        Http::fake([self::ADRESA => $this->odgovor('{"vazi_pravilo": true, "razlog": "Zato."}')]);

        $niz = (new Claude)->odgovoriJsonom('Kratko uputstvo.', 'Tekst strane.');

        $this->assertSame(['vazi_pravilo' => true, 'razlog' => 'Zato.'], $niz);

        Http::assertSent(function (Request $zahtev) {
            $telo = $zahtev->data();

            return $zahtev->url() === self::ADRESA
                && $zahtev->hasHeader('x-api-key', self::KLJUC)
                && $zahtev->hasHeader('anthropic-version', '2023-06-01')
                && $telo['model'] === 'claude-haiku-5-5'
                && $telo['max_tokens'] === 4000
                && ! array_key_exists('thinking', $telo)
                && $telo['system'] === 'Kratko uputstvo.'
                && $telo['messages'] === [['role' => 'user', 'content' => 'Tekst strane.']]
                && ! array_key_exists('temperature', $telo);
        });
    }

    // Ogledalo: model, adresa, vreme čekanja i temperatura (za stariji model) dolaze iz podešavanja.
    #[Test]
    public function model_adresa_vreme_cekanja_i_temperatura_dolaze_iz_podesavanja(): void
    {
        config()->set('claude.model', 'claude-sonnet-5-5');
        config()->set('claude.adresa', 'https://drugi.test/');
        config()->set('claude.vreme_cekanja', 7);
        config()->set('claude.temperatura', 0);
        $opcije = [];
        Http::fake(['*' => function (Request $zahtev, array $dobijene) use (&$opcije) {
            $opcije = $dobijene;

            return $this->odgovor('Evo.');
        }]);

        (new Claude)->odgovoriTekstom('U.', 'T.');

        Http::assertSent(fn (Request $zahtev) => $zahtev->url() === 'https://drugi.test/v1/messages' && $zahtev['model'] === 'claude-sonnet-5-5' && $zahtev['temperature'] === 0.0);
        $this->assertSame(7, $opcije['timeout']);
    }

    // Haiku 5.5 podrazumevano razmišlja; blok „thinking" ispada iz teksta, a razmišljanje se isključuje samo kad je tako podešeno.
    #[Test]
    public function razmisljanje_se_iskljucuje_samo_kad_je_podeseno_a_blok_razmisljanja_nije_tekst(): void
    {
        Http::fake([self::ADRESA => Http::response(['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'drugo', 'text' => '{"b": 2}'], ['type' => 'text', 'text' => '{"a": 1}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 400]])]);
        config()->set('claude.razmisljanje', 'iskljuceno');

        $this->assertSame(['a' => 1], (new Claude)->odgovoriJsonom('U.', 'T.'));
        Http::assertSent(fn (Request $zahtev) => $zahtev['thinking'] === ['type' => 'disabled']);

        // Ogledalo: bilo koja druga vrednost ne šalje ništa.
        config()->set('claude.razmisljanje', 'ukljuceno');
        (new Claude)->odgovoriJsonom('U.', 'T.');
        Http::assertSent(fn (Request $zahtev) => ! array_key_exists('thinking', $zahtev->data()));
    }

    #[Test]
    public function pozivalac_moze_da_skrati_vreme_cekanja_ali_ne_da_ga_produzi(): void
    {
        config()->set('claude.vreme_cekanja', 30);
        $vremena = [];
        Http::fake(['*' => function (Request $zahtev, array $dobijene) use (&$vremena) {
            $vremena[] = $dobijene['timeout'];

            return $this->odgovor('Da.');
        }]);

        (new Claude)->odgovoriTekstom('U.', 'T.', 5);
        (new Claude)->odgovoriTekstom('U.', 'T.', 500);
        (new Claude)->odgovoriTekstom('U.', 'T.');

        $this->assertSame([5, 30, 30], $vremena);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function oblikOdgovoraModela(): array
    {
        return [
            'čist objekat' => ['{"a": 1}', ['a' => 1]],
            'u ogradi' => ["```json\n{\"a\": 1}\n```", ['a' => 1]],
            'reč pre objekta' => ['Evo odgovora: {"a": 1}', ['a' => 1]],
            'reč posle objekta' => ['{"a": 1} Nadam se da pomaže.', ['a' => 1]],
            'razmaci oko' => ["  \n{\"a\": {\"b\": [1, 2]}}\n ", ['a' => ['b' => [1, 2]]]],
            'prazan objekat' => ['{}', []],
        ];
    }

    /** @param  array<string, mixed>  $ocekivano */
    #[Test]
    #[DataProvider('oblikOdgovoraModela')]
    public function json_se_vadi_i_iz_ograde_i_iz_teksta_oko_objekta(string $sadrzaj, array $ocekivano): void
    {
        Http::fake([self::ADRESA => $this->odgovor($sadrzaj)]);

        $this->assertSame($ocekivano, (new Claude)->odgovoriJsonom('U.', 'T.'));
    }

    /** @return array<string, array{0: string}> */
    public static function nijeObjekat(): array
    {
        return [
            'obična rečenica' => ['Ne mogu to da uradim.'],
            'niz' => ['[1, 2]'],
            'prazan odgovor' => [''],
            'odsečen objekat' => ['{"vazi_pravilo": true, "razlog": "Zato'],
            'broj' => ['42'],
        ];
    }

    #[Test]
    #[DataProvider('nijeObjekat')]
    public function odgovor_koji_nije_json_objekat_je_odgovor_nije_json(string $sadrzaj): void
    {
        Http::fake([self::ADRESA => $this->odgovor($sadrzaj)]);

        $this->expectException(OdgovorNijeJson::class);

        (new Claude)->odgovoriJsonom('U.', 'T.');
    }

    // Slobodan tekst: više delova se spaja, delovi koji nisu tekst ispadaju, a prazan odgovor je prazan tekst.
    #[Test]
    public function tekst_se_spaja_iz_delova_i_prazan_odgovor_je_prazan_tekst(): void
    {
        Http::fake([self::ADRESA => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => ' Prvo. '], ['type' => 'tool_use', 'id' => 'x', 'name' => 'y', 'input' => []], ['type' => 'text', 'text' => 'Drugo. ']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])
            ->push(['content' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]])]);
        $claude = new Claude;

        $this->assertSame('Prvo. Drugo.', $claude->odgovoriTekstom('U.', 'T.'));
        $this->assertSame('', $claude->odgovoriTekstom('U.', 'T.'));
    }

    // Bez ključa nema ni zahteva: isto kao kad model ne radi.
    #[Test]
    public function bez_kljuca_nema_zahteva_i_baca_nedostupna(): void
    {
        foreach ([null, '', '   '] as $kljuc) {
            config()->set('claude.kljuc', $kljuc);

            try {
                (new Claude)->odgovoriJsonom('U.', 'T.');
                $this->fail('poziv je prošao bez ključa');
            } catch (ModelNedostupan $izuzetak) {
                $this->assertSame('Claude API: ključ nije podešen (ANTHROPIC_API_KEY)', $izuzetak->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function mreza_koja_ne_radi_je_nedostupna_bez_ponavljanja(): void
    {
        $pokusaja = 0;
        Http::fake([self::ADRESA => function () use (&$pokusaja) {
            $pokusaja++;

            throw new ConnectionException('cURL error 6: Could not resolve host: api.anthropic.com');
        }]);

        try {
            (new Claude)->odgovoriTekstom('U.', 'T.');
            $this->fail('poziv je prošao');
        } catch (ModelNedostupan $izuzetak) {
            $this->assertSame('Claude API ne odgovara', $izuzetak->getMessage());
        }

        $this->assertSame(1, $pokusaja);
        Sleep::assertNeverSlept();
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function odbijenKljuc(): array
    {
        return ['HTTP 401' => [401, 'Claude API je odbio ključ (HTTP 401)'], 'HTTP 403' => [403, 'Claude API je odbio ključ (HTTP 403)'], 'HTTP 400' => [400, 'Claude API je odgovorio sa HTTP 400'], 'HTTP 500' => [500, 'Claude API je odgovorio sa HTTP 500']];
    }

    #[Test]
    #[DataProvider('odbijenKljuc')]
    public function status_koji_nije_uspeh_ni_preopterecenost_je_nedostupna_odmah_a_poruka_nema_kljuc(int $status, string $poruka): void
    {
        Http::fake([self::ADRESA => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'x-api-key '.self::KLJUC.' nije ispravan']], $status)]);

        try {
            (new Claude)->odgovoriTekstom('U.', 'T.');
            $this->fail('poziv je prošao');
        } catch (ModelNedostupan $izuzetak) {
            $this->assertSame($poruka, $izuzetak->getMessage());
            $this->assertStringNotContainsString(self::KLJUC, $izuzetak->getMessage().$izuzetak->getTraceAsString());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    /** @return array<string, array{0: int}> */
    public static function preopterecenost(): array
    {
        return ['HTTP 429' => [429], 'HTTP 529' => [529]];
    }

    // 429 i 529: dva ponavljanja sa čekanjem, pa isto kao kad model ne radi.
    #[Test]
    #[DataProvider('preopterecenost')]
    public function preopterecenost_se_ponavlja_najvise_dva_puta_pa_je_nedostupna(int $status): void
    {
        Http::fake([self::ADRESA => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Preopterećeno']], $status)]);

        try {
            (new Claude)->odgovoriTekstom('U.', 'T.');
            $this->fail('poziv je prošao');
        } catch (ModelNedostupan $izuzetak) {
            $this->assertSame('Claude API je odgovorio sa HTTP '.$status, $izuzetak->getMessage());
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(10)->seconds()]);
    }

    // Ogledalo: ako drugi pokušaj uspe, odgovor se vraća i čeka se samo jednom.
    #[Test]
    public function ponavljanje_koje_uspe_vraca_odgovor(): void
    {
        Http::fake([self::ADRESA => Http::sequence()->push(['type' => 'error'], 529)->push($this->odgovorTelo('Uspelo.'), 200)]);

        $this->assertSame('Uspelo.', (new Claude)->odgovoriTekstom('U.', 'T.'));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    // Retry-After skraćuje ili produžava čekanje, ali najviše 30 s.
    #[Test]
    public function retry_after_odredjuje_cekanje_do_trideset_sekundi(): void
    {
        Http::fake([self::ADRESA => Http::sequence()->push(['type' => 'error'], 429, ['retry-after' => '1'])->push(['type' => 'error'], 429, ['retry-after' => '600'])->push($this->odgovorTelo('Uspelo.'), 200)]);

        (new Claude)->odgovoriTekstom('U.', 'T.');

        Sleep::assertSequence([Sleep::for(1)->seconds(), Sleep::for(30)->seconds()]);
    }

    /** @return array<string, mixed> */
    private function odgovorTelo(string $sadrzaj): array
    {
        return ['content' => [['type' => 'text', 'text' => $sadrzaj]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 2]];
    }

    // Potrošnja: pozivi, tokeni iz „usage" i procena cene iz podešavanja; neuspeli pozivi se ne računaju, a model bez cene nema procenu.
    #[Test]
    public function potrosnja_zbraja_pozive_tokene_i_cenu(): void
    {
        Http::fake([self::ADRESA => Http::sequence()
            ->push([...$this->odgovorTelo('Prvi.'), 'usage' => ['input_tokens' => 400_000, 'output_tokens' => 20_000]], 200)
            ->push(['type' => 'error'], 500)
            ->push([...$this->odgovorTelo('Drugi.'), 'usage' => ['input_tokens' => 600_000, 'output_tokens' => 30_000]], 200)]);
        $claude = new Claude;

        $nula = $claude->potrosnja();
        $claude->odgovoriTekstom('U.', 'T.');
        try {
            $claude->odgovoriTekstom('U.', 'T.');
        } catch (ModelNedostupan) {
        }
        $claude->odgovoriTekstom('U.', 'T.');
        $potrosnja = $claude->potrosnja();

        $this->assertSame([0, 0, 0], [$nula->poziva, $nula->ulazniTokeni, $nula->izlazniTokeni]);
        $this->assertSame(['claude-haiku-5-5', 2, 1_000_000, 50_000], [$potrosnja->model, $potrosnja->poziva, $potrosnja->ulazniTokeni, $potrosnja->izlazniTokeni]);
        // 1 milion ulaznih po 0,10 i 50 hiljada izlaznih po 0,50 = 0,10 + 0,025.
        $this->assertEqualsWithDelta(0.125, $potrosnja->cena, 0.0000001);

        config()->set('claude.model', 'claude-sonnet-5-5');
        $this->assertEqualsWithDelta(2.0 + 0.5, $claude->potrosnja()->cena, 0.0000001);

        config()->set('claude.model', 'neki-nepoznat-model');
        $this->assertNull($claude->potrosnja()->cena);
    }

    // Ključ se ne vidi ni u čemu što klasa vraća ili baca: ni u poruci, ni u tragu poziva, ni u potrošnji.
    #[Test]
    public function kljuc_nije_ni_u_potrosnji_ni_u_poruci_nijedne_greske(): void
    {
        Http::fake([self::ADRESA => Http::sequence()->push($this->odgovorTelo('Dobro.'), 200)->push(['x' => 1], 401)->push(['x' => 1], 529)->push(['x' => 1], 529)->push(['x' => 1], 529)]);
        $claude = new Claude;
        $nalazi = [$claude->odgovoriTekstom('U.', 'T.'), json_encode($claude->potrosnja())];

        for ($i = 0; $i < 2; $i++) {
            try {
                $claude->odgovoriTekstom('U.', 'T.');
            } catch (ModelNedostupan $izuzetak) {
                $nalazi[] = $izuzetak->getMessage().$izuzetak->getTraceAsString().$izuzetak;
            }
        }

        $this->assertCount(4, $nalazi);
        $this->assertStringNotContainsString(self::KLJUC, implode("\n", $nalazi));
    }
}
