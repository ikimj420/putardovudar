<?php

namespace Tests\Feature\Baza;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\BazaTestCase;

// Paket 95/T2 (PITANJA-92 2): sesija starija od SESSION_LIFETIME se briše po rasporedu, pa „server privremeno pamti adresu i pregledač" na strani „Privatnost" važi i kad niko ne dolazi.
#[Group('baza')]
class StareSesijeSeBrisuTest extends BazaTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 31, 12, 0, 0, 'Europe/Belgrade'));
    }

    private function sesija(string $id, int $minutaStara): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => null, 'ip_address' => '203.0.113.9', 'user_agent' => 'ProbaPregledac/1.0', 'payload' => '', 'last_activity' => now()->subMinutes($minutaStara)->getTimestamp(),
        ]);
    }

    /** @return list<string> */
    private function preostale(): array
    {
        return DB::table('sessions')->orderBy('id')->pluck('id')->all();
    }

    #[Test]
    public function sesija_starija_od_vremena_trajanja_nestaje_a_svezija_ostaje(): void
    {
        $this->assertSame(120, config('session.lifetime'));
        $this->sesija('a-pre-dan-i-po', 36 * 60);
        $this->sesija('b-pre-121-minut', 121);
        $this->sesija('c-pre-119-minuta', 119);
        $this->sesija('d-upravo', 0);
        $this->assertCount(4, $this->preostale());

        $this->artisan('sesije:ocisti')->expectsOutput('Obrisano starih sesija: 2.')->assertSuccessful();

        $this->assertSame(['c-pre-119-minuta', 'd-upravo'], $this->preostale());
    }

    // Ogledalo: vreme trajanja je ono iz podešavanja, ne broj upisan u komandi.
    #[Test]
    public function granica_je_vreme_trajanja_iz_podesavanja(): void
    {
        config()->set('session.lifetime', 30);
        $this->sesija('a-pre-60-minuta', 60);
        $this->sesija('b-pre-20-minuta', 20);

        $this->artisan('sesije:ocisti')->expectsOutput('Obrisano starih sesija: 1.')->assertSuccessful();

        $this->assertSame(['b-pre-20-minuta'], $this->preostale());

        config()->set('session.lifetime', 600);
        $this->sesija('c-pre-9-sati', 9 * 60);

        $this->artisan('sesije:ocisti')->expectsOutput('Obrisano starih sesija: 0.')->assertSuccessful();

        $this->assertSame(['b-pre-20-minuta', 'c-pre-9-sati'], $this->preostale());
    }

    #[Test]
    public function bez_sesija_se_nista_ne_brise(): void
    {
        $this->artisan('sesije:ocisti')->expectsOutput('Obrisano starih sesija: 0.')->assertSuccessful();
    }

    #[Test]
    public function brisanje_stoji_u_rasporedu_svakog_sata(): void
    {
        $nadjeni = array_values(array_filter(app(Schedule::class)->events(), fn (Event $dogadjaj) => str_contains($dogadjaj->command, 'sesije:ocisti')));

        $this->assertCount(1, $nadjeni);
        $this->assertSame('0 * * * *', $nadjeni[0]->expression);
    }
}
