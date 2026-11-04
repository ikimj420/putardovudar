<?php

namespace Tests\Feature;

use App\Services\JavnaAdresa;
use App\Services\OcenaAdrese;
use App\Services\RazresivacImena;
use App\Services\SistemskiRazresivac;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Podrska\LazniRazresivac;
use Tests\TestCase;

// Paket 95/T1 (PITANJA-92 1): strana se preuzima samo sa javne adrese; petlja, privatna i „link-local" mreža, i adresa koja se ne razrešava, nisu javne.
class JavnaAdresaTest extends TestCase
{
    private function javna(): JavnaAdresa
    {
        return $this->app->make(JavnaAdresa::class);
    }

    /** @return array<string, array{string}> */
    public static function adrese_koje_nisu_javne(): array
    {
        return [
            'petlja' => ['http://127.0.0.1/poziv'],
            'petlja sa portom (Ollama)' => ['http://127.0.0.1:11434/api/chat'],
            'cela petlja 127/8' => ['http://127.9.9.9/'],
            'privatna 10/8' => ['http://10.1.2.3/x'],
            'privatna 172.16/12' => ['http://172.16.0.5/'],
            'privatna 192.168/16' => ['https://192.168.0.10/'],
            'link-local (metapodaci oblaka)' => ['http://169.254.169.254/latest/meta-data/'],
            'nula' => ['http://0.0.0.0/'],
            'zajednička 100.64/10' => ['http://100.64.0.1/'],
            'IPv6 petlja' => ['http://[::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
            'IPv6 privatna' => ['http://[fc00::1]/'],
            'IPv4 u IPv6 obliku' => ['http://[::ffff:127.0.0.1]/'],
            'sa korisnikom u adresi' => ['http://korisnik:lozinka@127.0.0.1/'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function adrese_koje_su_javne(): array
    {
        return [
            'IPv4' => ['https://93.184.216.34/poziv'],
            'IPv4 sa portom' => ['http://8.8.8.8:8080/'],
            'IPv6' => ['https://[2606:4700::1111]/'],
            'ime' => ['https://a.test/poziv-1'],
            'velika slova' => ['HTTPS://A.TEST/Poziv'],
        ];
    }

    #[Test]
    #[DataProvider('adrese_koje_nisu_javne')]
    public function adresa_cija_je_mreza_petlja_privatna_ili_link_local_nije_javna(string $adresa): void
    {
        $this->assertFalse($this->javna()->jeJavna($adresa));
    }

    // Ogledalo: javne adrese prolaze, pa merilo nije „odbija sve".
    #[Test]
    #[DataProvider('adrese_koje_su_javne')]
    public function javna_adresa_prolazi(string $adresa): void
    {
        $this->assertTrue($this->javna()->jeJavna($adresa));
    }

    /** @return array<string, array{string}> */
    public static function adrese_bez_veze_koja_se_sme_otvoriti(): array
    {
        return [
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://a.test/poziv'],
            'gopher' => ['gopher://a.test/'],
            'bez šeme' => ['//a.test/poziv'],
            'samo ime' => ['a.test/poziv'],
            'prazno' => [''],
            'bez imena' => ['http:///poziv'],
        ];
    }

    #[Test]
    #[DataProvider('adrese_bez_veze_koja_se_sme_otvoriti')]
    public function adresa_koja_nije_http_ili_https_nije_javna(string $adresa): void
    {
        $this->assertFalse($this->javna()->jeJavna($adresa));
    }

    // Ime se gleda po tome na šta se razrešava: jedna privatna adresa među javnim je dovoljna da se odbije; ime koje se ne razrešava nije javno, ali ni nejavno (vidi ocenu ispod).
    #[Test]
    public function ime_se_odbija_kad_se_razresava_na_privatnu_adresu_makar_i_uz_javnu(): void
    {
        $this->razresivac()->postavi('interno.test', ['10.0.0.5']);
        $this->razresivac()->postavi('mesovito.test', [LazniRazresivac::JAVNA_ADRESA, '127.0.0.1']);
        $this->razresivac()->postavi('mesovito-v6.test', [LazniRazresivac::JAVNA_ADRESA, '::1']);
        $this->razresivac()->postavi('nema.test', []);

        foreach (['interno.test', 'mesovito.test', 'mesovito-v6.test', 'nema.test'] as $ime) {
            $this->assertFalse($this->javna()->jeJavna('https://'.$ime.'/poziv'), $ime);
        }

        $this->assertTrue($this->javna()->jeJavna('https://obicno.test/poziv'));
    }

    // Van testova ime se razrešava sistemski: bez vezivanja u AppServiceProvider-u aplikacija ne bi imala razrešivača.
    #[Test]
    public function bez_lazne_zamene_aplikacija_razresava_ime_sistemski(): void
    {
        $this->app->forgetInstance(RazresivacImena::class);

        $this->assertInstanceOf(SistemskiRazresivac::class, $this->app->make(RazresivacImena::class));
    }

    // Pravi razrešivač (bez mreže): „localhost" i brojčani oblici petlje (decimalni, šesnaestostruki, osmični, skraćeni) moraju da budu odbijeni.
    #[Test]
    public function pravi_razresivac_odbija_localhost_i_brojcane_oblike_petlje(): void
    {
        $javna = new JavnaAdresa(new SistemskiRazresivac);

        $this->assertContains('127.0.0.1', (new SistemskiRazresivac)->adrese('localhost'));

        foreach (['http://localhost/', 'http://localhost:11434/', 'http://2130706433/', 'http://0x7f000001/', 'http://017700000001/', 'http://127.1/'] as $adresa) {
            $this->assertFalse($javna->jeJavna($adresa), $adresa);
        }
    }

    // Paket 99: tri ishoda umesto dva. „Nejavna" je odluka (petlja, privatna ili link-local mreža, loša šema), „ne razrešava se" nije: DNS ume samo trenutno da ne odgovori.
    #[Test]
    #[DataProvider('adrese_koje_nisu_javne')]
    public function ocena_adrese_koja_je_petlja_ili_privatna_mreza_je_nejavna(string $adresa): void
    {
        $this->assertSame(OcenaAdrese::Nejavna, $this->javna()->ocena($adresa));
    }

    #[Test]
    #[DataProvider('adrese_bez_veze_koja_se_sme_otvoriti')]
    public function ocena_adrese_koja_nije_http_ili_https_je_nejavna(string $adresa): void
    {
        $this->assertSame(OcenaAdrese::Nejavna, $this->javna()->ocena($adresa));
    }

    #[Test]
    #[DataProvider('adrese_koje_su_javne')]
    public function ocena_javne_adrese_je_javna(string $adresa): void
    {
        $this->assertSame(OcenaAdrese::Javna, $this->javna()->ocena($adresa));
    }

    #[Test]
    public function ocena_imena_zavisi_od_toga_na_sta_se_razresava(): void
    {
        $this->razresivac()->postavi('interno.test', ['10.0.0.5']);
        $this->razresivac()->postavi('mesovito.test', [LazniRazresivac::JAVNA_ADRESA, '127.0.0.1']);
        $this->razresivac()->postavi('nema.test', []);

        $this->assertSame(OcenaAdrese::Nejavna, $this->javna()->ocena('https://interno.test/x'));
        $this->assertSame(OcenaAdrese::Nejavna, $this->javna()->ocena('https://mesovito.test/x'));
        $this->assertSame(OcenaAdrese::NeRazresava, $this->javna()->ocena('https://nema.test/x'));
        $this->assertSame(OcenaAdrese::Javna, $this->javna()->ocena('https://obicno.test/x'));
        // Ogledalo: adresa koja je sama IP nikad nije „ne razrešava se"; i jeJavna je false i za nejavnu i za neproverljivu, a true samo za javnu.
        $this->assertSame(OcenaAdrese::Nejavna, $this->javna()->ocena('http://127.0.0.1/x'));
        $this->assertFalse($this->javna()->jeJavna('https://nema.test/x'));
        $this->assertTrue($this->javna()->jeJavna('https://obicno.test/x'));
    }

    // Pravi razrešivač (bez mreže za localhost i brojčane oblike): ono što se razreši na petlju je nejavno, a ime iz rezervisanog domena „.invalid" (RFC 2606) se ne razrešava ni na šta.
    #[Test]
    public function pravi_razresivac_razlikuje_petlju_od_imena_koje_ne_postoji(): void
    {
        $javna = new JavnaAdresa(new SistemskiRazresivac);

        foreach (['http://localhost/', 'http://2130706433/', 'http://0x7f000001/', 'http://127.1/'] as $adresa) {
            $this->assertSame(OcenaAdrese::Nejavna, $javna->ocena($adresa), $adresa);
        }

        $this->assertSame(OcenaAdrese::NeRazresava, $javna->ocena('https://nepostoji-123.invalid/poziv'));
    }
}
