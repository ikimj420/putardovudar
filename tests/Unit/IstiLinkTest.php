<?php

namespace Tests\Unit;

use App\Support\PrikazKalendara;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Paket 97/T6 (nalaz 12 iz PITANJA-92): „isti link" ne zavisi od http/https i „www." (Bibija je promenila oblik adrese u paketu 78), a strana sa drugom putanjom, upitom ili sajtom je druga strana.
class IstiLinkTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function isti(): array
    {
        return [
            'Bibija: http sa www i https bez www' => ['http://www.bibija.org.rs/konkurs-2026', 'https://bibija.org.rs/konkurs-2026/'],
            'Bibija: obrnuto' => ['https://bibija.org.rs/konkurs-2026/', 'http://www.bibija.org.rs/konkurs-2026'],
            'samo šema' => ['http://bibija.org.rs/konkurs-2026', 'https://bibija.org.rs/konkurs-2026'],
            'samo www' => ['https://www.bibija.org.rs/konkurs-2026', 'https://bibija.org.rs/konkurs-2026'],
            'velika slova sajta' => ['https://Bibija.ORG.rs/konkurs-2026', 'https://bibija.org.rs/konkurs-2026'],
            'kosa crta na kraju' => ['https://bibija.org.rs/konkurs-2026/', 'https://bibija.org.rs/konkurs-2026'],
            'razmaci unaokolo' => ['  https://bibija.org.rs/konkurs-2026 ', 'https://bibija.org.rs/konkurs-2026'],
            'koren sa i bez kose crte' => ['http://www.bibija.org.rs/', 'https://bibija.org.rs'],
            'podrazumevani port' => ['https://bibija.org.rs:443/konkurs-2026', 'http://bibija.org.rs:80/konkurs-2026'],
            'isti upit' => ['http://www.sajt.rs/?p=15', 'https://sajt.rs/?p=15'],
            'upit sa i bez kose crte pre njega' => ['https://sajt.rs?p=15', 'https://www.sajt.rs/?p=15'],
            'deo iza taraba se ne računa' => ['https://bibija.org.rs/konkurs-2026#prijava', 'https://bibija.org.rs/konkurs-2026'],
            'isti tekst koji nije adresa' => ['neki tekst', 'neki tekst/'],
        ];
    }

    /** @return array<string, array{?string, ?string}> */
    public static function razliciti(): array
    {
        return [
            'druga putanja' => ['https://bibija.org.rs/konkurs-2026', 'https://bibija.org.rs/konkurs-2027'],
            'putanja sa drugim velikim slovom' => ['https://bibija.org.rs/Konkurs', 'https://bibija.org.rs/konkurs'],
            'drugi sajt' => ['https://bibija.org.rs/konkurs-2026', 'https://romanipen.org.rs/konkurs-2026'],
            'sajt sa istim početkom' => ['https://bibija.org.rs.drugi.test/konkurs-2026', 'https://bibija.org.rs/konkurs-2026'],
            'poddomen nije www' => ['https://novosti.bibija.org.rs/konkurs-2026', 'https://bibija.org.rs/konkurs-2026'],
            'www kao kraj prvog dela imena' => ['https://xwww.sajt.rs/konkurs-2026', 'https://xsajt.rs/konkurs-2026'],
            'www u sredini imena' => ['https://sajt.www.rs/konkurs-2026', 'https://sajt.rs/konkurs-2026'],
            'drugi upit' => ['https://sajt.rs/?p=15', 'https://sajt.rs/?p=16'],
            'upit naspram bez upita' => ['https://sajt.rs/?p=15', 'https://sajt.rs/'],
            'drugi port' => ['https://bibija.org.rs:8443/konkurs-2026', 'https://bibija.org.rs/konkurs-2026'],
            'dublja putanja' => ['https://bibija.org.rs/konkurs-2026/prijava', 'https://bibija.org.rs/konkurs-2026'],
            'drugi tekst koji nije adresa' => ['neki tekst', 'drugi tekst'],
            'prazno i prazno' => ['', ''],
            'null i null' => [null, null],
            'prazno i adresa' => ['', 'https://bibija.org.rs/'],
            'adresa i null' => ['https://bibija.org.rs/', null],
            'samo razmaci' => ['   ', '   '],
        ];
    }

    #[Test]
    #[DataProvider('isti')]
    public function adrese_koje_se_razlikuju_samo_u_obliku_su_isti_link(string $prva, string $druga): void
    {
        $this->assertTrue(PrikazKalendara::istiLink($prva, $druga));
        $this->assertTrue(PrikazKalendara::istiLink($druga, $prva), 'obrnut redosled');
    }

    // Ogledalo: različita strana, sajt, upit ili port, i prazno, nisu isti link.
    #[Test]
    #[DataProvider('razliciti')]
    public function razlicite_strane_i_prazno_nisu_isti_link(?string $prva, ?string $druga): void
    {
        $this->assertFalse(PrikazKalendara::istiLink($prva, $druga));
        $this->assertFalse(PrikazKalendara::istiLink($druga, $prva), 'obrnut redosled');
    }
}
