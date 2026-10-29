<?php

namespace Tests\Unit;

use App\Services\TekstStrane;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TekstStraneTest extends TestCase
{
    #[Test]
    public function iz_html_a_ostaje_samo_vidljiv_tekst_latinicom(): void
    {
        $html = '<html><head><title>Naslov</title><style>p{color:red}</style></head><body><nav>Meni</nav>'
            .'<script>var x = "rok 1.1.2030";</script><h1>Конкурс</h1><p>Rok:&nbsp;20.&nbsp;oktobra &amp; dalje</p><footer>Podnožje</footer></body></html>';

        $this->assertSame('Konkurs Rok: 20. oktobra & dalje', TekstStrane::izHtmla($html));
    }

    // Paket 70/T2: oznake usred reda ne lome reč, blokovi je odvajaju (primer: Erasmus+ stranica, „<strong>n</strong><strong>ovembar</strong>").
    #[Test]
    public function oznake_usred_reda_ne_lome_rec_a_blokovi_i_dalje_odvajaju(): void
    {
        $this->assertSame('Rok je 10. novembar 2026. do 23.59 časova.', TekstStrane::izHtmla('<p><strong>Rok je 10. </strong><strong>n</strong><strong>ovembar</strong><strong> 2026.</strong><strong> do 23.59 časova.</strong></p>'));
        // Ogledalo: dve reči u dve oznake jedna do druge, sa razmakom između, ostaju odvojene.
        $this->assertSame('Rok je 20. oktobra', TekstStrane::izHtmla('<b>Rok</b> <b>je</b> <i>20.</i> <em>oktobra</em>'));
        $this->assertSame('na sajtu s2s.fonis.rs, gde', TekstStrane::izHtmla('na sajtu <a href="http://s2s.fonis.rs">s2s.fonis.rs</a>, gde'));

        // Svaka oznaka usred reda, jedna po jedna: reč podeljena njome ostaje cela.
        foreach (['strong', 'em', 'span', 'a', 'b', 'i', 'u', 'small', 'sup', 'mark', 'font', 'abbr', 'time', 'label'] as $oznaka) {
            $this->assertSame('novembar', TekstStrane::izHtmla("n<$oznaka>ovembar</$oznaka>"), $oznaka);
            $this->assertSame('novembar', TekstStrane::izHtmla("<$oznaka>n</$oznaka>ovembar"), $oznaka);
        }

        foreach (['p', 'div', 'li', 'h2', 'td', 'section', 'blockquote'] as $oznaka) {
            $this->assertSame('prvi drugi', TekstStrane::izHtmla("<$oznaka>prvi</$oznaka><$oznaka>drugi</$oznaka>"), $oznaka);
        }

        $this->assertSame('prvi drugi', TekstStrane::izHtmla('prvi<br>drugi'));
        $this->assertSame('prvi drugi', TekstStrane::izHtmla('prvi<br />drugi'));
        $this->assertSame('Naslov Tekst', TekstStrane::izHtmla('<h1>Naslov</h1>Tekst'));
        // Veličina slova u oznaci i atributi ne smetaju.
        $this->assertSame('prvi drugi', TekstStrane::izHtmla('<DIV class="x">prvi</DIV><P id="y">drugi</P>'));
    }

    // Ogledalo: obična latinica bez oznaka prolazi nepromenjena.
    #[Test]
    public function tekst_bez_oznaka_i_cirilice_ostaje_isti(): void
    {
        $this->assertSame('Rok je 20. oktobra 2026.', TekstStrane::izHtmla('Rok je 20. oktobra 2026.'));
    }

    #[Test]
    public function poredjenje_ne_zavisi_od_velikih_slova_razmaka_i_navodnika(): void
    {
        $this->assertSame('rok je 20. oktobra', TekstStrane::normalizuj("  „Rok   je\n20. OKTOBRA\" "));
        $this->assertNotSame(TekstStrane::normalizuj('rok je 20. oktobra'), TekstStrane::normalizuj('rok je 21. oktobra'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function znakoviKojiPocinjuBajtomZaNavodnike(): array
    {
        return [
            'sat na početku' => ['⏰ Prijave do 30. jula.', '⏰ prijave do 30. jula.'],
            'kvačica na početku' => ['✅ Rok: 30. jul', '✅ rok: 30. jul'],
            'strelica na početku' => ['➡ Prijava', '➡ prijava'],
            'sat na kraju' => ['Prijave ⏰', 'prijave ⏰'],
            'kvačica na kraju' => ['Rok ✅', 'rok ✅'],
            'navodnici oko znaka na početku' => ['„⏰ Rok“', '⏰ rok'],
            'navodnici oko znaka na kraju' => ['"Rok ✅"', 'rok ✅'],
            'samo znak' => ['⏰', '⏰'],
        ];
    }

    // Paket 74 (dopuna): skidanje navodnika po bajtovima je sekao prvi bajt znaka koji počinje isto kao „ “ ” (bajt E2: ⏰ ✅ ➡), pa je citat modela postajao nevažeći UTF-8
    // i preg_match je rušio celu obradu. Izmereno na pravom odgovoru Claude-a (citat „⏰ …" sa stranice Youth.rs).
    #[Test]
    #[DataProvider('znakoviKojiPocinjuBajtomZaNavodnike')]
    public function normalizacija_ne_sece_znak_koji_pocinje_bajtom_navodnika(string $ulaz, string $izlaz): void
    {
        $normalizovano = TekstStrane::normalizuj($ulaz);

        $this->assertTrue(mb_check_encoding($normalizovano, 'UTF-8'), $ulaz);
        $this->assertSame($izlaz, $normalizovano);
    }

    private const MENI = '<div class="meni"><ul><li>Početna</li><li>Savetovalište za mlade</li></ul></div>';

    private const PODNOZJE = '<div class="podnozje">Prijavi se na naš bilten</div>';

    // Paket 76 (PITANJA-76, izbor A): meni, bočne trake i podnožje su van <article>; kad strana ima tačno jedan, čita se samo on.
    #[Test]
    public function strana_sa_tacno_jednim_article_daje_samo_njegov_tekst(): void
    {
        $html = '<html><body>'.self::MENI.'<article><h1>Poziv</h1><p>Pozivamo mlade da se prijave.</p></article>'.self::PODNOZJE.'</body></html>';

        $this->assertSame('Poziv Pozivamo mlade da se prijave.', TekstStrane::izHtmla($html, true));
    }

    // Ogledalo: bez zahteva za <article> (podrazumevano) isti HTML daje celu stranu, sa menijem i podnožjem, kao do sada.
    #[Test]
    public function bez_zahteva_za_article_cita_se_cela_strana(): void
    {
        $html = '<html><body>'.self::MENI.'<article><h1>Poziv</h1><p>Pozivamo mlade da se prijave.</p></article>'.self::PODNOZJE.'</body></html>';

        $this->assertSame('Početna Savetovalište za mlade Poziv Pozivamo mlade da se prijave. Prijavi se na naš bilten', TekstStrane::izHtmla($html));
    }

    // Ogledalo: strana bez <article> daje isti tekst sa zahtevom i bez njega.
    #[Test]
    public function strana_bez_article_daje_ceo_tekst_i_kad_se_trazi_samo_article(): void
    {
        $html = '<html><body>'.self::MENI.'<div><h1>Poziv</h1><p>Pozivamo mlade da se prijave.</p></div>'.self::PODNOZJE.'</body></html>';

        $this->assertSame('Početna Savetovalište za mlade Poziv Pozivamo mlade da se prijave. Prijavi se na naš bilten', TekstStrane::izHtmla($html, true));
        $this->assertSame(TekstStrane::izHtmla($html), TekstStrane::izHtmla($html, true));
    }

    // Ogledalo: sa dva <article> (spisak objava, povezani tekstovi) ostaje cela strana.
    #[Test]
    public function strana_sa_dva_article_daje_ceo_tekst_i_kad_se_trazi_samo_article(): void
    {
        $html = '<html><body>'.self::MENI.'<article><p>Prvi poziv.</p></article><article><p>Drugi poziv.</p></article>'.self::PODNOZJE.'</body></html>';

        $this->assertSame('Početna Savetovalište za mlade Prvi poziv. Drugi poziv. Prijavi se na naš bilten', TekstStrane::izHtmla($html, true));
    }

    // Otvoren a nezatvoren (ili obrnuto) <article> nije pouzdan, pa ostaje cela strana.
    #[Test]
    public function article_koji_nije_zatvoren_ne_sece_stranu(): void
    {
        $nezatvoren = '<html><body>'.self::MENI.'<article><p>Poziv bez kraja.</p>'.self::PODNOZJE.'</body></html>';
        $bezPocetka = '<html><body>'.self::MENI.'<p>Poziv bez početka.</p></article>'.self::PODNOZJE.'</body></html>';

        $this->assertStringContainsString('Savetovalište za mlade', TekstStrane::izHtmla($nezatvoren, true));
        $this->assertStringContainsString('Prijavi se na naš bilten', TekstStrane::izHtmla($nezatvoren, true));
        $this->assertStringContainsString('Savetovalište za mlade', TekstStrane::izHtmla($bezPocetka, true));

        // Jedan početak i dva kraja: ne zna se gde članak stvarno staje, pa cela strana.
        $visakKraja = '<html><body>'.self::MENI.'<article><p>Poziv.</p></article><p>Ostatak.</p></article>'.self::PODNOZJE.'</body></html>';
        $this->assertStringContainsString('Ostatak.', TekstStrane::izHtmla($visakKraja, true));
        $this->assertStringContainsString('Savetovalište za mlade', TekstStrane::izHtmla($visakKraja, true));
    }

    // Reč „article" u imenu druge oznake („article-card", „articles") nije <article>: ovde je pravi samo jedan, pa se čita on.
    #[Test]
    public function oznaka_cije_ime_pocinje_sa_article_nije_article(): void
    {
        $html = '<html><body>'.self::MENI.'<article-card>Kartica</article-card><articles>Lista</articles><article><p>Pravi poziv.</p></article>'.self::PODNOZJE.'</body></html>';

        $this->assertSame('Pravi poziv.', TekstStrane::izHtmla($html, true));
    }

    // Paket 59/T4: razmak ispred . , ; : ! ? ) i iza ( se skida (strana ga ima iz izvlačenja teksta, citat modela ne); razmak između reči ostaje.
    #[Test]
    public function razmak_ispred_interpunkcije_i_iza_zagrade_se_skida_a_izmedju_reci_ostaje(): void
    {
        foreach ([
            'briselskom vremenu .' => 'briselskom vremenu.', 's2s.fonis.rs ,' => 's2s.fonis.rs,', 'a ; b : c ! d ?' => 'a; b: c! d?', '( studenti i đaci )' => '(studenti i đaci)',
            "vremenu\u{00A0}." => 'vremenu.', "rok \n ." => 'rok.',
        ] as $ulaz => $izlaz) {
            $this->assertSame($izlaz, TekstStrane::normalizuj($ulaz), $ulaz);
        }

        // Ogledalo: razmak posle interpunkcije, između reči i u datumu ostaje, a citat bez razmaka je isti kao strana sa razmakom.
        $this->assertSame('rok je 20. oktobra 2026.', TekstStrane::normalizuj('Rok je 20. oktobra 2026.'));
        $this->assertSame('15. 11. 2026.', TekstStrane::normalizuj('15. 11. 2026.'));
        $this->assertSame('ana i ivan', TekstStrane::normalizuj('Ana  i  Ivan'));
        $this->assertSame(TekstStrane::normalizuj('rok je 20. oktobra 2026 .'), TekstStrane::normalizuj('rok je 20. oktobra 2026.'));
        $this->assertNotSame(TekstStrane::normalizuj('rok je 20 . oktobra'), TekstStrane::normalizuj('rok je 20 oktobra'));
    }
}
