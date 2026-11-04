<?php

namespace Tests\Feature\Baza;

use App\Support\PrikazPomocnika;
use App\Support\PrikazPrivatnosti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 80/T3: strana „Privatnost" nosi tekst iz paketa, bez rečenice koja nije tačna (PITANJA-80.md).
#[Group('baza')]
class PrivatnostTest extends BazaTestCase
{
    use RefreshDatabase;

    #[Test]
    public function strana_ima_naslov_uvod_i_pasuse_tim_redom(): void
    {
        $this->get(route('privatnost'))->assertOk()->assertSeeInOrder([
            PrikazPrivatnosti::NASLOV,
            'Kratko i jasno: šta sajt pamti, a šta ne.',
            'Ne treba ti nalog.',
            'Ne čuvamo tvoja pitanja pomoćniku ni ono što upišeš u „Napiši moj".',
            'Ne koristimo ih ni za šta drugo.',
            'Da bi napisao odgovor, pomoćnik ih šalje Claude-u, firmi Anthropic.',
            'Anthropic ih ne koristi za učenje modela.',
            'Obično ih briše u roku od 30 dana.',
            'Kad predložiš priliku, čuvamo samo link i napomenu koje upišeš, da bismo proverili priliku.',
            'Ne upisuj lične podatke u napomenu.',
            'Dok si na sajtu, server privremeno pamti adresu tvog uređaja i vrstu pregledača.',
            'To mu treba da bi sajt radio i da bi sprečio zloupotrebu.',
            'Sajt koristi samo kolačiće koji su potrebni da radi. Nema reklama ni praćenja.',
            'Linkovi vode na druge sajtove (izvore prilika i organizacije). Oni imaju svoja pravila o privatnosti.',
        ]);
        $this->assertCount(6, PrikazPrivatnosti::PASUSI);
    }

    // Ogledalo: rečenica koja nije tačna ne stoji na strani. „Napiši moj" traži ime, adresu i telefon (SabloniSeeder), pa „ne traži ime, mejl ni broj telefona" ne važi.
    #[Test]
    public function recenica_da_sajt_ne_trazi_ime_mejl_ni_telefon_nije_na_strani(): void
    {
        $html = $this->get(route('privatnost'))->assertOk()->getContent();

        $this->assertStringNotContainsString('ne traži ime', $html);
        $this->assertStringNotContainsString('broj telefona', $html);
        $this->assertStringContainsString('Ne treba ti nalog.', $html);
    }

    #[Test]
    public function strana_je_u_putanji_i_nema_spoljnih_adresa(): void
    {
        $html = $this->get(route('privatnost'))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Privatnost - Putardo Vudar</title>', $html);
        $this->assertStringContainsString('<span>Privatnost</span>', $html);
        $this->assertSame(0, preg_match('#(?:href|src|action)="https?://(?!'.preg_quote(parse_url(url('/'), PHP_URL_HOST), '#').')#', $html));
    }

    // Paket 100/T4: pomoćnik više ne radi na našem računaru, pa te rečenice na strani nema; tekst koji je zamenjuje kaže kome se pitanje šalje.
    #[Test]
    public function recenica_da_pomocnik_radi_na_nasem_racunaru_nije_na_strani_a_nova_kaze_kome_se_salje(): void
    {
        $html = $this->get(route('privatnost'))->assertOk()->getContent();

        $this->assertStringNotContainsString('na našem računaru', $html);
        $this->assertStringNotContainsString('ne šalje drugim firmama', $html);
        $this->assertStringContainsString('pomoćnik ih šalje Claude-u, firmi Anthropic.', $html);
    }

    // Svaka rečenica novih tekstova ima do 15 reči (paket 100/T4); skup mora biti neprazan, inače bi petlja prošla ni nad čim.
    #[Test]
    public function svaka_recenica_novih_tekstova_ima_do_petnaest_reci(): void
    {
        $recenice = [];

        foreach ([PrikazPrivatnosti::UPISANO, PrikazPomocnika::NE_CUVAMO, PrikazPomocnika::LICNI_PODACI] as $tekst) {
            array_push($recenice, ...preg_split('/(?<=[.!?])\s+/u', $tekst));
        }

        $this->assertCount(8, $recenice);

        foreach ($recenice as $recenica) {
            $this->assertLessThanOrEqual(15, count(preg_split('/\s+/u', trim($recenica))), $recenica);
        }

        // Ogledalo: merilo stvarno broji; rečenica od šesnaest reči ne bi prošla.
        $this->assertGreaterThan(15, count(preg_split('/\s+/u', 'Ovo je jedna rečenica koja ima tačno šesnaest reči, računajući i one najkraće reči u njoj.')));
    }

    // Natpis ispod polja vodi na stranu „Privatnost", i ta strana postoji.
    #[Test]
    public function natpis_uz_polja_vodi_na_stranu_privatnost(): void
    {
        $this->get(route('pomocnik.index'))->assertOk()->assertSee('<a href="'.route('privatnost').'">Privatnost</a>', false);
        $this->get(route('privatnost'))->assertOk();
    }
}
