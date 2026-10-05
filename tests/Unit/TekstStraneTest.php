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
}
