<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// Raspored se samo čita i meri; ovaj test ne pokreće nijedan posao.
class RasporedUvozaTest extends TestCase
{
    /** @return list<Event> */
    private function dogadjaji(): array
    {
        return app(Schedule::class)->events();
    }

    private function dnevniUvoz(): Event
    {
        $nadjeni = array_values(array_filter($this->dogadjaji(), fn (Event $dogadjaj) => str_contains($dogadjaj->command, 'uvoz:dnevno')));

        $this->assertCount(1, $nadjeni, 'Dnevni uvoz mora da stoji u rasporedu tačno jednom.');

        return $nadjeni[0];
    }

    #[Test]
    public function u_rasporedu_je_samo_dnevni_uvoz_jednom_dnevno_ujutru_po_beogradskom_vremenu(): void
    {
        $dogadjaj = $this->dnevniUvoz();

        $this->assertCount(1, $this->dogadjaji());
        $this->assertSame('0 6 * * *', $dogadjaj->expression);
        $this->assertSame('Europe/Belgrade', (string) $dogadjaj->timezone);
    }

    #[Test]
    public function dnevni_uvoz_se_ne_preklapa_sa_samim_sobom_a_mutex_ne_blokira_vise_od_tri_sata(): void
    {
        $dogadjaj = $this->dnevniUvoz();

        $this->assertTrue($dogadjaj->withoutOverlapping);
        $this->assertSame(180, $dogadjaj->expiresAt);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function trenuci(): array
    {
        return [
            '06:00 u Beogradu (letnje vreme, 04:00 UTC)' => ['2026-10-10 04:00:00', true],
            '05:59 u Beogradu' => ['2026-10-10 03:59:00', false],
            '06:01 u Beogradu' => ['2026-10-10 04:01:00', false],
            '06:00 UTC je 08:00 u Beogradu' => ['2026-10-10 06:00:00', false],
            '06:00 u Beogradu (zimsko vreme, 05:00 UTC)' => ['2026-11-10 05:00:00', true],
            '04:00 UTC zimi je 05:00 u Beogradu' => ['2026-11-10 04:00:00', false],
        ];
    }

    #[Test]
    #[DataProvider('trenuci')]
    public function posao_je_na_redu_tacno_u_sest_ujutru_po_beogradskom_vremenu(string $utc, bool $naRedu): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

        try {
            $this->assertSame($naRedu, $this->dnevniUvoz()->isDue($this->app));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function spisak_rasporeda_pokazuje_dnevni_uvoz_bez_pokretanja(): void
    {
        $izlaz = Artisan::call('schedule:list');
        $tekst = (string) preg_replace('/\e\[[0-9;]*m/', '', Artisan::output());

        $this->assertSame(0, $izlaz);
        $this->assertStringContainsString('0 6 * * *', $tekst);
        $this->assertStringContainsString('uvoz:dnevno', $tekst);
    }
}
