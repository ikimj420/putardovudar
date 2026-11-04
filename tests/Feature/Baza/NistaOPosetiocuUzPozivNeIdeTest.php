<?php

namespace Tests\Feature\Baza;

use App\Enums\VrstaPrilike;
use App\Models\Prilika;
use App\Models\Sablon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;
use Tests\Podrska\LazniClaude;

// Paket 100/T3 (Ivan, 05.11.2026.): uz poziv ka Claude-u ne ide ništa o posetiocu: ni adresa, ni sesija, ni preglednik, ni kolačić, ni metadata.user_id; samo uputstvo, tekst pitanja i zapisi iz baze.
#[Group('baza')]
class NistaOPosetiocuUzPozivNeIdeTest extends BazaTestCase
{
    use RefreshDatabase;

    private const ADRESA_POSETIOCA = '203.0.113.77';

    private const PREGLEDAC = 'ProbaPregledac/9.9 (Posetilac-4412)';

    private const PREUSMERIO = 'https://odakle.example.org/Posetilac-4412';

    private const PROSLEDJENA_ADRESA = '198.51.100.9';

    private const KOLACIC = 'KOLACIC-Posetilac-4412';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('claude.razmisljanje', 'iskljuceno');
        $this->travelTo(Carbon::create(2026, 11, 5, 12, 0, 0, 'Europe/Belgrade'));
        Prilika::factory()->objavljena()->create(['naslov' => 'Radnik u skladištu', 'vrsta' => VrstaPrilike::Posao, 'mesto' => 'Niš', 'rok' => '2026-12-15', 'kratak_opis' => 'Puno radno vreme.', 'naziv_izvora' => 'Probni izvor', 'link_izvora' => 'https://primer.rs/skladiste-nis']);
        Http::fake([LazniClaude::ADRESA => fn (Request $zahtev) => LazniClaude::jeJson($zahtev)
            ? LazniClaude::odgovor(json_encode(['vrsta' => 'posao', 'grad' => 'Niš', 'kome' => null, 'kljucne_reci' => []]))
            : LazniClaude::odgovor('Našao sam posao u Nišu.')]);
    }

    /** @return array<string, string> server i zaglavlja jednog posetioca, sa znacima koji se traže u onome što ode ka Claude-u */
    private function posetilac(): array
    {
        return ['REMOTE_ADDR' => self::ADRESA_POSETIOCA, 'HTTP_USER_AGENT' => self::PREGLEDAC, 'HTTP_REFERER' => self::PREUSMERIO, 'HTTP_X_FORWARDED_FOR' => self::PROSLEDJENA_ADRESA, 'HTTP_COOKIE' => 'probni='.self::KOLACIC];
    }

    private function pitajIPisi(): void
    {
        $sablon = Sablon::factory()->objavljen()->create([
            'naslov' => 'Pismo', 'objasnjenje' => 'Za pismo.',
            'polja' => [['kljuc' => 'ime', 'oznaka' => 'Ime', 'objasnjenje' => null, 'dugo' => false], ['kljuc' => 'tekst', 'oznaka' => 'Tekst', 'objasnjenje' => null, 'dugo' => true]],
            'tekst' => "Poštovani,\n\n{tekst}\n\nS poštovanjem,\n{ime}",
        ]);

        $this->withServerVariables($this->posetilac())->post(route('pomocnik.pitaj'), ['pitanje' => 'Ima li posla u Nišu?'])->assertOk();
        $this->withServerVariables($this->posetilac())->post(route('pomocnik.napisi', $sablon->slug), ['polje' => ['ime' => 'Ana Anić', 'tekst' => 'Molim za pomoć.']])->assertOk();
    }

    /** @return list<Request> */
    private function zahtevi(): array
    {
        return Http::recorded()->map(fn (array $par) => $par[0])->values()->all();
    }

    #[Test]
    public function zahtev_ka_claude_u_nosi_samo_model_uputstvo_i_jednu_poruku_bez_metapodataka(): void
    {
        $this->pitajIPisi();

        $zahtevi = $this->zahtevi();
        // Pitanje: formular pa odgovor; „Napiši moj": jedan poziv.
        $this->assertCount(3, $zahtevi);

        foreach ($zahtevi as $zahtev) {
            $this->assertSame('POST', $zahtev->method());
            $this->assertSame(LazniClaude::ADRESA, $zahtev->url());
            // thinking i temperature su podešavanje okruženja (config/claude.php), ne podatak o posetiocu; svaki drugi ključ bi bio nov put za podatke.
            $this->assertSame([], array_diff(array_keys($zahtev->data()), ['model', 'max_tokens', 'thinking', 'temperature', 'system', 'messages']), json_encode(array_keys($zahtev->data())));
            $this->assertSame([], array_diff(['model', 'max_tokens', 'system', 'messages'], array_keys($zahtev->data())));
            $this->assertSame(['type' => 'disabled'], $zahtev->data()['thinking']);
            $this->assertCount(1, $zahtev->data()['messages']);
            $this->assertSame('user', $zahtev->data()['messages'][0]['role']);
            $this->assertIsString($zahtev->data()['messages'][0]['content']);
            $this->assertArrayNotHasKey('metadata', $zahtev->data());
            $this->assertArrayNotHasKey('user', $zahtev->data());
        }
    }

    #[Test]
    public function zaglavlja_ka_claude_u_su_samo_kljuc_verzija_i_ono_sto_dodaje_http_klijent(): void
    {
        $this->pitajIPisi();

        $zahtevi = $this->zahtevi();
        $this->assertNotEmpty($zahtevi);

        foreach ($zahtevi as $zahtev) {
            $imena = array_map('strtolower', array_keys($zahtev->headers()));
            sort($imena);

            // host, content-length, user-agent i accept dodaje sam HTTP klijent; bilo koje drugo zaglavlje (X-Forwarded-For, Cookie, Referer…) bi došlo iz aplikacije.
            $this->assertSame(['accept', 'anthropic-version', 'content-length', 'content-type', 'host', 'user-agent', 'x-api-key'], $imena, json_encode($imena));
            $this->assertStringNotContainsString('Posetilac', $zahtev->header('User-Agent')[0]);
        }
    }

    // Ogledalo: znaci posetioca su stvarno stigli do aplikacije (inače „nema ih u pozivu" ne bi ništa dokazivalo), a u pozivu ih nema ni u zaglavljima, ni u telu, ni u sesiji.
    #[Test]
    public function nista_sto_je_posetilac_poslao_ne_ide_ka_claude_u(): void
    {
        $this->pitajIPisi();

        $zahtev = $this->app['request'];
        $this->assertSame(self::ADRESA_POSETIOCA, $zahtev->ip());
        $this->assertSame(self::PREGLEDAC, $zahtev->userAgent());
        $this->assertSame(self::PREUSMERIO, $zahtev->headers->get('referer'));
        $this->assertSame(self::PROSLEDJENA_ADRESA, $zahtev->headers->get('x-forwarded-for'));
        $this->assertStringContainsString(self::KOLACIC, (string) $zahtev->headers->get('cookie'));
        $sesija = $zahtev->hasSession() ? $zahtev->session()->getId() : '';
        $this->assertNotSame('', $sesija);

        $poslato = collect($this->zahtevi())->map(fn (Request $poziv) => json_encode(['zaglavlja' => $poziv->headers(), 'telo' => $poziv->data()], JSON_UNESCAPED_UNICODE))->implode("\n");

        foreach ([self::ADRESA_POSETIOCA, self::PREGLEDAC, 'Posetilac-4412', self::PROSLEDJENA_ADRESA, self::KOLACIC, $sesija, csrf_token()] as $znak) {
            $this->assertNotSame('', $znak);
            $this->assertStringNotContainsString($znak, $poslato, $znak);
        }

        // Ono što treba da ide, ide: pitanje i zapis iz baze.
        $this->assertStringContainsString('Ima li posla u Nišu?', $poslato);
        $this->assertStringContainsString('Radnik u skladištu', $poslato);
    }
}
