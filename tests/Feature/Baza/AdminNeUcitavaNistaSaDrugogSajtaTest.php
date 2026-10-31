<?php

namespace Tests\Feature\Baza;

use App\Filament\AvatarBezSpoljneAdrese;
use App\Models\Prilika;
use App\Models\User;
use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AdminBazaTestCase;

// Paket 95/T4 (PITANJA-93 1): admin ne učitava ništa sa drugog sajta, ni avatar sa ui-avatars.com; avatar je slika sklopljena u aplikaciji.
#[Group('baza')]
class AdminNeUcitavaNistaSaDrugogSajtaTest extends AdminBazaTestCase
{
    /** @return array<string, array{string}> */
    public static function strane(): array
    {
        return [
            'početna' => [''],
            'prilike' => ['/prilike'],
            'izmena prilike' => ['/prilike/1/edit'],
            'kalendar' => ['/kalendar'],
            'organizacije' => ['/organizacije'],
            'predlozi' => ['/predlozi'],
            'šabloni' => ['/sabloni'],
            'vodiči' => ['/vodici'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Prilika::factory()->objavljena()->create(['id' => 1]);
    }

    /** @return list<string> adrese koje strana učitava: skripta, slika, okvir, stil, obrazac i url() u stilu */
    private function ucitane(string $html): array
    {
        preg_match_all('#<(?:script|img|iframe|source|video|audio|embed)\b[^>]*\ssrc="([^"]*)"|<link\b[^>]*\shref="([^"]*)"|<form\b[^>]*\saction="([^"]*)"|url\(\s*[\'"]?([^)\'"]+)#i', $html, $nadjeno);

        return array_values(array_filter(array_merge($nadjeno[1], $nadjeno[2], $nadjeno[3], $nadjeno[4])));
    }

    /** @return list<string> imena sajtova (ne puta) sa kojih strana nešto učitava */
    private function spoljniSajtovi(string $html): array
    {
        $moj = parse_url(url('/'), PHP_URL_HOST);

        return array_values(array_unique(array_filter(
            array_map(fn (string $adresa) => parse_url($adresa, PHP_URL_HOST), $this->ucitane($html)),
            fn (mixed $host) => is_string($host) && $host !== $moj,
        )));
    }

    private function otvori(string $putanja): string
    {
        return $this->actingAs(User::factory()->create(['name' => 'Ivan Demirović']))->get('/'.config('admin.path').$putanja)->assertOk()->getContent();
    }

    #[Test]
    #[DataProvider('strane')]
    public function admin_ne_ucitava_nista_sa_drugog_sajta_a_avatar_je_slika_iz_aplikacije(string $putanja): void
    {
        $html = $this->otvori($putanja);
        $adrese = $this->ucitane($html);
        $avatari = array_filter($adrese, fn (string $adresa) => str_starts_with($adresa, 'data:image/svg+xml;base64,'));

        $this->assertGreaterThanOrEqual(2, count($adrese), 'merilo mora da obiđe bar stil i skript: '.$putanja);
        $this->assertNotEmpty($avatari, 'merilo mora da vidi avatar: '.$putanja);
        $this->assertSame([], $this->spoljniSajtovi($html), $putanja);
    }

    #[Test]
    public function strana_za_prijavu_ne_ucitava_nista_sa_drugog_sajta(): void
    {
        $html = $this->get('/'.config('admin.path').'/login')->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, count($this->ucitane($html)));
        $this->assertSame([], $this->spoljniSajtovi($html));
    }

    // Ogledalo: sa Filamentovim podrazumevanim izvorom avatara merilo vidi ui-avatars.com, pa zelen prolaz gore nije „merilo ne vidi spoljne adrese".
    #[Test]
    public function sa_podrazumevanim_izvorom_filamenta_merilo_vidi_ui_avatars(): void
    {
        $panel = Filament::getPanel('admin');
        $izvor = $panel->getDefaultAvatarProvider();
        $this->assertSame(AvatarBezSpoljneAdrese::class, $izvor);

        $panel->defaultAvatarProvider(UiAvatarsProvider::class);

        try {
            $this->assertSame(['ui-avatars.com'], $this->spoljniSajtovi($this->otvori('')));
        } finally {
            $panel->defaultAvatarProvider($izvor);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function imena(): array
    {
        return [
            'ime i prezime' => ['Ivan Demirović', 'ID'],
            'jedna reč' => ['Ivan', 'I'],
            'više reči, samo prva dva slova' => ['Ana Maria Anić', 'AM'],
            'mala slova' => ['ivan demirović', 'ID'],
            'interpunkcija na početku reči' => ['[SISTEM] Admin', 'SA'],
            'slovo sa kvačicom' => ['Čedomir Šaranović', 'ČŠ'],
            'razmaci unaokolo' => ['  Ivan   Demirović ', 'ID'],
            'prazno ime' => ['', '?'],
            'samo interpunkcija' => ['*** ---', '?'],
        ];
    }

    #[Test]
    #[DataProvider('imena')]
    public function avatar_je_svg_sa_inicijalima_bez_ijedne_spoljne_adrese(string $ime, string $inicijali): void
    {
        $adresa = (new AvatarBezSpoljneAdrese)->get(new User(['name' => $ime]));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $adresa);
        $svg = (string) base64_decode(substr($adresa, strlen('data:image/svg+xml;base64,')), true);
        $xml = simplexml_load_string($svg);

        $this->assertNotFalse($xml, 'SVG mora da bude ispravan XML');
        $this->assertSame($inicijali, (string) $xml->text);
        $this->assertSame([], array_filter($this->ucitane($svg), fn (string $a) => parse_url($a, PHP_URL_HOST) !== null));
        $this->assertStringNotContainsString('ui-avatars', $adresa.$svg);
    }

    // Ime je tuđ tekst: oznaka u imenu ne sme da postane oznaka u slici.
    #[Test]
    public function oznake_u_imenu_ne_postaju_deo_slike(): void
    {
        $adresa = (new AvatarBezSpoljneAdrese)->get(new User(['name' => '&"\'<x> <script>alert(1)</script>']));
        $svg = (string) base64_decode(substr($adresa, strlen('data:image/svg+xml;base64,')), true);

        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertSame(['rect', 'text'], array_map(fn (\SimpleXMLElement $dete) => $dete->getName(), iterator_to_array($xml->children(), false)));
        $this->assertSame('XS', (string) $xml->text);
    }
}
