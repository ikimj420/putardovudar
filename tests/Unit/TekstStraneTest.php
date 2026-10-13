<?php

namespace Tests\Unit;

use App\Services\TekstStrane;
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
