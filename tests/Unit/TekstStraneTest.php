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
