<?php

namespace Tests\Feature\Ollama;

use App\Services\Model\ModelObrade;
use App\Services\Ollama\LokalnaAdresa;
use App\Services\Ollama\Ollama;
use App\Services\Ollama\OllamaNedostupna;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BezLokalnogOkruzenja;
use Tests\Podrska\HvatacDnevnika;
use Tests\TestCase;

// Paket 95/T3 (PITANJA-92 3): pomoćnik i „Napiši moj" govore samo sa Ollamom na petlji ili privatnoj mreži; obrada uvoza (šalje samo tekst javnih strana) ne.
class OllamaSamoLokalnoTest extends TestCase
{
    use BezLokalnogOkruzenja;

    private const PITANJE = 'Gde ima stipendija za Rome u Nišu?';

    /** @return array<string, array{string}> */
    public static function lokalne_adrese(): array
    {
        return [
            'petlja (podrazumevana)' => ['http://127.0.0.1:11434'],
            'cela petlja 127/8' => ['http://127.5.5.5:11434'],
            'localhost' => ['http://localhost:11434'],
            'IPv6 petlja' => ['http://[::1]:11434'],
            'IPv6 petlja, dugi oblik' => ['http://[0:0:0:0:0:0:0:1]:11434'],
            'IPv4 petlja u IPv6 obliku' => ['http://[::ffff:127.0.0.1]:11434'],
            'privatna 10/8' => ['http://10.0.0.5:11434'],
            'privatna 172.16/12 (početak)' => ['http://172.16.0.1:11434'],
            'privatna 172.16/12 (kraj)' => ['http://172.31.255.254:11434'],
            'privatna 192.168/16' => ['http://192.168.1.20:11434'],
            'IPv6 privatna fc00::/7' => ['http://[fd12:3456::1]:11434'],
            'https' => ['https://192.168.1.20:11434'],
            'ime u mreži (razrešava se na privatnu)' => ['http://ollama-lan.test:11434'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function adrese_koje_nisu_lokalne(): array
    {
        return [
            'javna IPv4' => ['http://93.184.216.34:11434'],
            'javna IPv4, 8.8.8.8' => ['http://8.8.8.8:11434'],
            'javna IPv6' => ['http://[2606:4700::1111]:11434'],
            'javna IPv4 u IPv6 obliku' => ['http://[::ffff:8.8.8.8]:11434'],
            'prva adresa posle 172.16/12' => ['http://172.32.0.1:11434'],
            'poslednja pre 172.16/12' => ['http://172.15.255.255:11434'],
            'link-local' => ['http://169.254.169.254:11434'],
            'IPv6 link-local' => ['http://[fe80::1]:11434'],
            'nula' => ['http://0.0.0.0:11434'],
            'javno ime' => ['https://ollama.example.test:11434'],
            'ime koje se ne razrešava' => ['http://nema.test:11434'],
            'ime sa privatnom i javnom adresom' => ['http://mesovito.test:11434'],
            'ne http' => ['ftp://127.0.0.1:11434'],
            'bez šeme' => ['127.0.0.1:11434'],
            'prazno' => [''],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->razresivac()->postavi('ollama-lan.test', ['192.168.1.20']);
        $this->razresivac()->postavi('nema.test', []);
        $this->razresivac()->postavi('mesovito.test', ['192.168.1.20', '93.184.216.34']);
    }

    private function odgovor(): mixed
    {
        return Http::response(['message' => ['content' => '{"a":1}']]);
    }

    #[Test]
    #[DataProvider('lokalne_adrese')]
    public function pomocnik_zove_ollamu_na_petlji_i_privatnoj_mrezi(string $adresa): void
    {
        config()->set('ollama.adresa', $adresa);
        Http::fake(['*' => $this->odgovor()]);

        $this->assertSame(['a' => 1], $this->app->make(Ollama::class)->odgovoriJsonom('U', self::PITANJE));
        Http::assertSentCount(1);
    }

    // Ogledalo: adresa koja nije petlja ni privatna mreža se odbija pre veze, istim izuzetkom kao kad Ollama ne radi, pa pomoćnik pokazuje istu poruku.
    #[Test]
    #[DataProvider('adrese_koje_nisu_lokalne')]
    public function pomocnik_odbija_adresu_koja_nije_lokalna_i_nista_ne_salje(string $adresa): void
    {
        config()->set('ollama.adresa', $adresa);
        Http::fake(['*' => $this->odgovor()]);

        try {
            $this->app->make(Ollama::class)->odgovoriTekstom('U', self::PITANJE);
            $this->fail('Poziv na '.$adresa.' je prošao.');
        } catch (OllamaNedostupna $izuzetak) {
            $this->assertSame('Ollama: adresa nije lokalna', $izuzetak->getMessage());
        }

        Http::assertNothingSent();
    }

    // U dnevnik ide samo da je poziv odbijen: ni pitanje, ni adresa (može da nosi korisnika i lozinku).
    #[Test]
    public function u_dnevnik_ide_samo_odbijanje_bez_pitanja_i_adrese(): void
    {
        config()->set('ollama.adresa', 'https://korisnik:lozinka@ollama.example.test:11434');
        $dnevnik = new HvatacDnevnika;

        try {
            $this->app->make(Ollama::class)->odgovoriJsonom('Uputstvo.', self::PITANJE);
            $this->fail('Poziv je prošao.');
        } catch (OllamaNedostupna) {
            // očekivano
        }

        $this->assertCount(1, $dnevnik->poruke);
        $this->assertSame('warning', $dnevnik->poruke[0]->level);
        $this->assertSame('Ollama: adresa nije lokalna, poziv je odbijen.', $dnevnik->poruke[0]->message);
        $this->assertSame([], $dnevnik->poruke[0]->context);
    }

    // Obrada uvoza nije pomoćnik: ona dobija isti objekat kao do sada, sa adresom iz podešavanja, i ne gleda da li je lokalna.
    #[Test]
    public function obrada_uvoza_zove_ollamu_i_na_adresi_koja_nije_lokalna(): void
    {
        config()->set('ollama.adresa', 'https://ollama.example.test:11434');
        config()->set('claude.model_obrade', 'ollama');
        Http::fake(['*' => $this->odgovor()]);

        $model = $this->app->make(ModelObrade::class);

        $this->assertInstanceOf(Ollama::class, $model);
        $this->assertSame(['a' => 1], $model->odgovoriJsonom('U', 'Tekst javne strane.'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function ollama_napravljena_bez_ogranicenja_zove_bilo_koju_adresu_iz_podesavanja(): void
    {
        config()->set('ollama.adresa', 'https://ollama.example.test:11434');
        Http::fake(['*' => $this->odgovor()]);

        $this->assertSame(['a' => 1], (new Ollama)->odgovoriJsonom('U', 'T'));
        $this->assertInstanceOf(LokalnaAdresa::class, $this->app->make(LokalnaAdresa::class));
    }
}
