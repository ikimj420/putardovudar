<?php

namespace Tests\Feature;

use App\Services\Pomocnik\ProveraNapisanogTeksta;
use App\Services\Pomocnik\ProveraOdgovora;
use App\Support\Latinica;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Paket 67/T2 (Ivan, 11.10.2026.): odgovor u kom posle pretvaranja ćirilice ostane i jedno slovo koje nije latinica ne prolazi proveru (pomoćnik i „Napiši moj").
class ProveraPismaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> tekst, ima li slovo van latinice */
    public static function tekstovi(): array
    {
        return [
            // Ne prolaze: slova drugih pisama, i usred rečenice, i samo jedno.
            'kineska slova iz probe' => ['Stipendija za romske srednjoškolce je online i限期等待 user Izvinjavam se, mogao bih da to ponovim?', true],
            'samo kineska slova' => ['限期等待', true],
            'jedno kinesko slovo' => ['Rok je do 15. novembra 限.', true],
            'japanska slova' => ['Našao sam こんにちは posao.', true],
            'korejska slova' => ['Našao sam 안녕 posao.', true],
            'ćirilica van srpske azbuke ostaje' => ["Našao sam \u{044B} posao.", true],
            'ukrajinska ćirilica' => ["Konkurs \u{0457}", true],
            'makedonska ćirilica' => ["Poziv \u{0453}", true],
            'arapsko pismo' => ['Posao: مرحبا', true],
            'hebrejsko pismo' => ["Posao \u{05E9}\u{05DC}\u{05D5}\u{05DD}", true],
            'grčko slovo' => ['Stipendija α', true],
            // Prolaze: latinica sa znakovima, brojevi, interpunkcija i znaci.
            'ime Mirjeta Krasniqi Gashi' => ['Mirjeta Krasniqi Gashi', false],
            'ime Zoë' => ['Zoë', false],
            'ë ü ç ö ä' => ['Zoë Müller Çelik Björk Ärlig', false],
            'srpska latinica' => ['Đorđe Šćepan želi čaj', false],
            'brojevi i datum' => ['Rok je 15.11.2026. u 12:30, cena 5 000 RSD.', false],
            'navodnici i crtice' => ['Konkurs „Mladi talenat“ — prijave do 1. 12.', false],
            'emoji nije slovo' => ["Posao \u{1F600} u Nišu", false],
            'mikro znak' => ["5 \u{00B5}m", false],
            'prazno' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('tekstovi')]
    public function slovo_van_latinice_se_prepoznaje_a_latinica_sa_znakovima_prolazi(string $tekst, bool $ima): void
    {
        $this->assertSame($ima, Latinica::imaSlovaVanLatinice($tekst));
    }

    // Merilo prvo dokazuje da obilazi obe strane: i tekstove koji padaju i one koji prolaze.
    #[Test]
    public function merilo_obilazi_tekstove_koji_padaju_i_one_koji_prolaze(): void
    {
        $this->assertGreaterThan(8, count(array_filter(self::tekstovi(), fn (array $primer) => $primer[1])));
        $this->assertGreaterThan(6, count(array_filter(self::tekstovi(), fn (array $primer) => ! $primer[1] && $primer[0] !== '')));
    }

    // Srpska ćirilica se pretvara u latinicu pre provere, pa tekst pisan srpskom ćirilicom prolazi; ono što ostane ćirilica ne prolazi.
    #[Test]
    public function srpska_cirilica_je_pretvorena_pre_provere_a_ostala_slova_ne_prolaze(): void
    {
        $srpska = Latinica::izCirilice("\u{0420}\u{0430}\u{0434}\u{043D}\u{0438}\u{043A} \u{0443} \u{0441}\u{043A}\u{043B}\u{0430}\u{0434}\u{0438}\u{0448}\u{0442}\u{0443}");

        $this->assertSame('Radnik u skladištu', $srpska);
        $this->assertFalse(Latinica::imaSlovaVanLatinice($srpska));
        $this->assertTrue(Latinica::imaSlovaVanLatinice(Latinica::izCirilice("\u{0420}\u{0430}\u{0434}\u{043D}\u{0438}\u{043A} \u{044B}")));
    }

    #[Test]
    #[DataProvider('tekstovi')]
    public function provera_odgovora_pomocnika_odbija_slovo_van_latinice_i_pusta_latinicu(string $tekst, bool $ima): void
    {
        $razlog = (new ProveraOdgovora)->razlogOdbijanja($tekst, new Collection, new Collection);

        // Latinica može da padne na drugom pravilu (datum kog nema u zapisima); ovde se meri samo ovo pravilo.
        $ima ? $this->assertSame('slova koja nisu latinica', $razlog) : $this->assertNotSame('slova koja nisu latinica', $razlog);
    }

    #[Test]
    #[DataProvider('tekstovi')]
    public function provera_napisanog_teksta_odbija_slovo_van_latinice_i_pusta_latinicu(string $tekst, bool $ima): void
    {
        $razlog = (new ProveraNapisanogTeksta)->razlogOdbijanja($tekst, $tekst);

        $this->assertSame($ima ? 'slova koja nisu latinica' : null, $tekst === '' ? null : $razlog);
    }

    // Provera slova ne sme da sakrije ranije razloge: prazan i predug tekst se i dalje prijavljuju svojim razlogom.
    #[Test]
    public function ranije_provere_ostaju_prve(): void
    {
        $this->assertSame('prazan odgovor', (new ProveraOdgovora)->razlogOdbijanja('', new Collection, new Collection));
        $this->assertSame('predug odgovor', (new ProveraOdgovora)->razlogOdbijanja(str_repeat('限', ProveraOdgovora::NAJVISE_ZNAKOVA + 1), new Collection, new Collection));
        $this->assertSame('prazan tekst', (new ProveraNapisanogTeksta)->razlogOdbijanja('  ', ''));
        $this->assertSame('predug tekst', (new ProveraNapisanogTeksta)->razlogOdbijanja(str_repeat('限', ProveraNapisanogTeksta::NAJVISE_ZNAKOVA + 1), ''));
    }
}
